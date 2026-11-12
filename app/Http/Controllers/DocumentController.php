<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Scopes\ExerciseScope;
use App\Support\BranchScope;
use App\Support\Highlight;
use App\Support\PrintPeriod;
use App\Support\StockState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Bons de vente et d'achat venus de Gescomm (agent 1.0.7), comme sa "Consultation des Ventes" : la liste des bons,
 * jour par jour, puis un bon et ses lignes (sa page, ou seul pour l'apercu F2 de la liste). sales.view, et seulement
 * les branches dont le compte voit les ventes (BranchScope::salesIds) : un bon d'une autre branche n'existe pas (404).
 * Montants de vente : prices.sale ; montants d'achat et marge : prices.buy.
 */
class DocumentController extends Controller
{
    /** ?period= : nombre de jours, aujourd'hui compris (null = tout l'historique). */
    public const PERIODS = ['1' => 1, '7' => 7, '30' => 30, 'all' => null];

    /** ?f= : tous les bons, ou ceux qui ont au moins une ligne rendue (quantite negative). */
    public const FILTERS = ['all', 'returns'];

    /** Prefixe des routes de chaque sorte de bon (sales.index, purchases.show...). */
    public const ROUTES = ['sale' => 'sales', 'purchase' => 'purchases'];

    private const PER_PAGE = 100;

    /** Une impression : tous les bons de la periode, jusqu'a cette limite (annoncee sur la page au-dela). */
    private const PRINT_LIMIT = 5000;

    private const PARTNER_KIND = ['sale' => 'customer', 'purchase' => 'supplier'];

    public function sales(Request $request): View
    {
        return $this->list($request, 'sale');
    }

    public function purchases(Request $request): View
    {
        return $this->list($request, 'purchase');
    }

    public function sale(Request $request, Document $document): View
    {
        return $this->bon($request, 'sale', $document);
    }

    public function purchase(Request $request, Document $document): View
    {
        return $this->bon($request, 'purchase', $document);
    }

    public function salesPrint(Request $request): View
    {
        return $this->printList($request, 'sale');
    }

    public function purchasesPrint(Request $request): View
    {
        return $this->printList($request, 'purchase');
    }

    public function salePrint(Request $request, Document $document): View
    {
        return $this->printBon($request, 'sale', $document);
    }

    public function purchasePrint(Request $request, Document $document): View
    {
        return $this->printBon($request, 'purchase', $document);
    }

    private function list(Request $request, string $kind): View
    {
        $money = $this->money($request, $kind);
        $selected = BranchScope::selected($request);
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        // un agent d'avant 1.0.7 n'envoie pas les bons : sa branche est nommee, jamais comptee comme "aucun bon"
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $ids = $ready->pluck('id')->all();
        $period = (string) $request->query('period', '30');
        $period = array_key_exists($period, self::PERIODS) ? $period : '30';
        $days = self::PERIODS[$period];
        // un exercice clos ouvert : les jours se comptent depuis son dernier jour (ExerciseScope::now)
        $since = $days === null ? null : ExerciseScope::now()->subDays($days - 1)->toDateString();
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $base = fn (string $f) => $this->filtered($kind, $ids, $since, $q, $f);

        $documents = $this->listing($base($filter), $kind)
            ->orderByDesc('documents.date')->orderByDesc('documents.time')->orderByDesc('documents.number')
            // sans "partial" : les liens des pages ouvrent la page entiere, meme apres une recherche pendant la frappe
            ->simplePaginate(self::PER_PAGE)->appends($request->except(['partial', 'page']));

        // les jours de cette page, avec le nombre de bons et le montant du jour entier (une page peut couper un jour)
        $shownDays = $documents->getCollection()->map(fn (Document $d) => $d->date?->toDateString())->filter()->unique()->values();
        $dayTotals = $shownDays->isEmpty() ? collect() : $base($filter)
            ->whereIn('documents.date', $shownDays)
            ->groupBy('documents.date')
            ->selectRaw('documents.date as day, COUNT(*) as bons, SUM(documents.amount) as amount')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $names = $ready->pluck('name', 'id');

        // ?partial=1 : seulement le bloc sous la recherche (filtres, total, bons, pages), pour la recherche pendant la frappe
        return view($request->boolean('partial') ? 'documents._list' : 'documents.index', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'q' => $q,
            'period' => $period,
            'filter' => $filter,
            'money' => $money,
            'documents' => $documents,
            'groups' => $documents->getCollection()->groupBy(fn (Document $d) => $d->date?->toDateString() ?? '')
                ->map(fn ($bons, string $day) => [
                    'label' => $this->dayLabel($day),
                    'bons' => (int) ($dayTotals[$day]->bons ?? $bons->count()),
                    'amount' => $money && isset($dayTotals[$day]) ? round((float) $dayTotals[$day]->amount, 2) : null,
                    'rows' => $bons->map(fn (Document $d) => [
                        'url' => route(self::ROUTES[$kind].'.show', $d),
                        'number' => $d->number,
                        'time' => $d->time ? substr($d->time, 0, 5) : null,
                        'branch' => $names[$d->branch_id] ?? null,
                        'hue' => Branch::hueFor($d->branch_id),
                        'partner' => $d->partner_name ?? $d->partner_code,
                        'partner_code' => $d->partner_name ? $d->partner_code : null,
                        'partner_url' => $d->partner_id ? route(PartnerController::ROUTES[self::PARTNER_KIND[$kind]].'.show', $d->partner_id) : null,
                        'lines' => (int) $d->lines_count,
                        'returns' => (int) $d->returns_count,
                        'amount' => $money ? $d->amount : null,
                        'served_by' => $d->served_by,
                        'payment' => $d->payment,
                    ]),
                ]),
            'counts' => collect(self::FILTERS)->mapWithKeys(fn (string $f) => [$f => $base($f)->count()]),
            'total' => $money ? round((float) $base($filter)->sum('documents.amount'), 2) : null,
            'branches' => $branches->count(),
            'selected' => $selected,
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }

    /**
     * Impression de la liste : les bons de la periode et de la recherche, jour par jour, du plus ancien au plus recent,
     * avec le total de chaque jour et de la periode. Memes branches et memes montants que la liste.
     */
    private function printList(Request $request, string $kind): View
    {
        $money = $this->money($request, $kind);
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $ids = $ready->pluck('id')->all();
        $window = PrintPeriod::read($request, self::PERIODS, '30');
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $base = fn () => $this->filtered($kind, $ids, $window['from'], $q, $filter)
            ->when($window['to'], fn (Builder $b) => $b->where('documents.date', '<=', $window['to']));

        $documents = $this->listing($base(), $kind)
            ->orderBy('documents.date')->orderBy('documents.time')->orderBy('documents.number')
            ->limit(self::PRINT_LIMIT)->get();
        $days = $base()->groupBy('documents.date')
            ->selectRaw('documents.date as day, COUNT(*) as bons, SUM(documents.amount) as amount')->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));
        $names = $ready->pluck('name', 'id');

        return view('print.journal', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'money' => $money,
            'q' => $q,
            'filter' => $filter,
            'window' => $window,
            'groups' => $documents->groupBy(fn (Document $d) => $d->date?->toDateString() ?? '')->map(fn ($bons, string $day) => [
                'label' => $this->dayLabel($day),
                'bons' => (int) ($days[$day]->bons ?? $bons->count()),
                'amount' => $money && isset($days[$day]) ? round((float) $days[$day]->amount, 2) : null,
                'rows' => $bons->map(fn (Document $d) => [
                    'number' => $d->number,
                    'time' => $d->time ? substr($d->time, 0, 5) : null,
                    'branch' => $names[$d->branch_id] ?? null,
                    'partner' => $d->partner_name ?? $d->partner_code,
                    'partner_code' => $d->partner_name ? $d->partner_code : null,
                    'lines' => (int) $d->lines_count,
                    'returns' => (int) $d->returns_count,
                    'amount' => $money ? $d->amount : null,
                    'served_by' => $d->served_by,
                    'payment' => $d->payment,
                ]),
            ]),
            'shown' => $documents->count(),
            'count' => (int) $days->sum('bons'),
            'total' => $money ? round((float) $days->sum('amount'), 2) : null,
            'letter' => $this->letterOf($ready),
            'branchNames' => $ready->pluck('name'),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }

    /**
     * Impression d'un bon. Avec les prix (si le compte les voit), ou sans pour preparer ou controler la marchandise :
     * ?prices=0, une case a cocher devant chaque ligne. Jamais la marge ni le stock : ce papier part chez le client.
     */
    private function printBon(Request $request, string $kind, Document $document): View
    {
        $data = $this->bonData($request, $kind, $document);

        return view('print.bon', $data + [
            'withPrices' => $data['money'] && $request->query('prices') !== '0',
        ]);
    }

    private function bon(Request $request, string $kind, Document $document): View
    {
        $data = $this->bonData($request, $kind, $document);

        // ?partial=1 : le bon seul, pour l'apercu de la liste (F2)
        return $request->boolean('partial') ? view('documents._bon', $data + ['inModal' => true]) : view('documents.show', $data);
    }

    /**
     * Ce qu'un bon montre, sur sa page et sur le papier.
     *
     * @return array<string, mixed>
     */
    private function bonData(Request $request, string $kind, Document $document): array
    {
        $user = $request->user();
        $allowed = $user->salesBranchIds();
        $branch = $document->branch;
        // un bon d'une branche dont le compte ne voit pas les ventes n'existe pas pour lui
        abort_unless($document->kind === $kind && $branch?->active
            && ($allowed === null || in_array($document->branch_id, $allowed, true)), 404);

        $money = $this->money($request, $kind);
        $buy = $user->can('prices.buy');
        $lines = $document->lines()->get();
        // le stock de chaque article dans cette branche, si le compte en voit le stock (comme "Stock" dans le bon de Gescomm)
        $stock = BranchStock::where('branch_id', $branch->id)->whereIn('code_key', $lines->pluck('code_key')->unique())->get()->groupBy('code_key');
        $seesStock = $user->seesBranch($branch);
        $partner = $document->partner_code === null ? null : Partner::where('branch_id', $branch->id)
            ->where('kind', self::PARTNER_KIND[$kind])->where('code', $document->partner_code)->first();
        $costed = $lines->whereNotNull('cost');

        return [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'document' => $document,
            'branch' => $branch,
            'day' => $this->dayLabel($document->date?->toDateString() ?? ''),
            'partner' => $partner,
            'partnerUrl' => $partner ? route(PartnerController::ROUTES[self::PARTNER_KIND[$kind]].'.show', $partner) : null,
            'money' => $money,
            'seesStock' => $seesStock,
            'lines' => $lines->map(function (DocumentLine $line) use ($stock, $money, $seesStock) {
                $rows = $stock->get($line->code_key, collect());
                $row = $rows->firstWhere('code_raw', $line->code_raw) ?? $rows->first();

                return [
                    'position' => $line->position,
                    'code' => $line->code_raw,
                    'designation' => $row?->designation,
                    'unit' => $row?->unit,
                    'qty' => $line->qty,
                    'back' => $line->qty !== null && $line->qty < 0,
                    'price' => $money ? $line->price : null,
                    'discount' => $money && $line->discount ? $line->discount : null,
                    'total' => $money ? $line->total : null,
                    'stock' => $seesStock ? $row?->stock : null,
                    'state' => $seesStock && $row ? StockState::of($row->stock, $row->stock_alert, $row->stock_max) : null,
                ];
            }),
            'returns' => $lines->filter(fn (DocumentLine $l) => $l->qty !== null && $l->qty < 0)->count(),
            // marge = montant - quantite x prix d'achat du jour de la vente (PRIXACHAT), comme sur la page d'un article
            'margin' => $kind === 'sale' && $buy && $costed->isNotEmpty()
                ? round($costed->sum(fn (DocumentLine $l) => (float) $l->total - (float) $l->qty * (float) $l->cost), 2) : null,
            'marginPartial' => $costed->count() < $lines->count(),
        ];
    }

    /** Les bons avec le nom du client et leur nombre de lignes et de lignes rendues. */
    private function listing(Builder $documents, string $kind): Builder
    {
        $lines = fn () => DocumentLine::selectRaw('COUNT(*)')->whereColumn('document_lines.document_id', 'documents.id');

        return $documents
            ->leftJoin('partners', fn ($join) => $join->on('partners.branch_id', '=', 'documents.branch_id')
                ->on('partners.code', '=', 'documents.partner_code')
                ->where('partners.kind', '=', self::PARTNER_KIND[$kind]))
            ->select(['documents.*', 'partners.name as partner_name', 'partners.id as partner_id'])
            ->selectSub($lines(), 'lines_count')
            ->selectSub($lines()->where('document_lines.qty', '<', 0), 'returns_count');
    }

    /** L'en-tete d'un etat : celui de la branche s'il n'y en a qu'une, sinon de la principale si elle en est. */
    private function letterOf($branches): ?Branch
    {
        return $branches->count() === 1 ? $branches->first() : $branches->firstWhere('is_main', true);
    }

    /** Bons d'une sorte dans ces branches, la periode, la recherche (numero, code ou nom du client) et le filtre. */
    private function filtered(string $kind, array $ids, ?string $since, string $q, string $filter): Builder
    {
        return Document::query()
            ->where('documents.kind', $kind)
            ->whereIn('documents.branch_id', $ids)
            ->when($since, fn (Builder $b) => $b->where('documents.date', '>=', $since))
            ->when($filter === 'returns', fn (Builder $b) => $b->whereExists(fn ($sub) => $sub->selectRaw('1')->from('document_lines')
                ->whereColumn('document_lines.document_id', 'documents.id')->where('document_lines.qty', '<', 0)))
            // chaque mot doit se trouver (comme la recherche du stock) : au debut du numero du bon, au debut du code du
            // client / fournisseur, ou dans son nom ; la page cherche pendant la frappe (?partial=1)
            ->when($q !== '', function (Builder $b) use ($q, $kind) {
                foreach (Highlight::terms($q) as $word) {
                    $like = Highlight::like($word);
                    $b->where(function (Builder $w) use ($word, $like, $kind) {
                        if (ctype_digit($word) && strlen($word) <= 15) {
                            $w->orWhereRaw("CAST(documents.number AS CHAR) LIKE ? ESCAPE '!'", [$like.'%']);
                        }
                        $w->orWhereRaw("UPPER(documents.partner_code) LIKE ? ESCAPE '!'", [$like.'%'])
                            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('partners')
                                ->whereColumn('partners.branch_id', 'documents.branch_id')
                                ->whereColumn('partners.code', 'documents.partner_code')
                                ->where('partners.kind', self::PARTNER_KIND[$kind])
                                ->whereRaw("UPPER(partners.name) LIKE ? ESCAPE '!'", ['%'.$like.'%']));
                    });
                }
            });
    }

    /** Les montants d'une sorte de bon : prix de vente pour les ventes, prix d'achat pour les achats. */
    private function money(Request $request, string $kind): bool
    {
        return $request->user()->can($kind === 'sale' ? 'prices.sale' : 'prices.buy');
    }

    /**
     * Le jour d'un bon : "اليوم", "أمس", sinon le jour de la semaine, dans la langue choisie ; puis la date.
     *
     * @return array{name: string, date: string}
     */
    private function dayLabel(string $day): array
    {
        if ($day === '') {
            return ['name' => '—', 'date' => ''];
        }
        $date = Carbon::parse($day)->locale(app()->getLocale());

        return [
            'name' => match (true) {
                $date->isToday() => __('documents.today'),
                $date->isYesterday() => __('documents.yesterday'),
                default => $date->translatedFormat('l'),
            },
            'date' => $date->format('d/m/Y'),
        ];
    }
}
