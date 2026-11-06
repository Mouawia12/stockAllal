<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Support\BranchScope;
use App\Support\PartnerInsights;
use App\Support\PrintPeriod;
use App\Support\ProductActivity;
use App\Support\StockState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Clients (GC002) et fournisseurs (GC004) venus de Gescomm (agent 1.0.7), facon Odoo : la liste (recherche, ce que
 * chacun a achete sur la periode, actifs, bloques), puis la fiche : coordonnees, chiffres de la periode, nature de la
 * relation (App\Support\PartnerInsights), et trois boutons qui ouvrent ses bons, versements et articles dans une fenetre
 * par pages (records, partners/_records).
 * sales.view, et seulement les branches dont le compte voit les ventes (BranchScope::salesIds) : une fiche d'une
 * autre branche n'existe pas (404). Montants : prices.sale pour les clients, prices.buy pour les fournisseurs.
 * Pas encore de solde : la facon dont Gescomm le calcule ("Autre" dans les versements) se verifie d'abord avec
 * agent/tools/check-balances.ps1.
 */
class PartnerController extends Controller
{
    /** ?period= de la liste : bons et montant de chacun sur la periode (null = tout l'historique). */
    public const LIST_PERIODS = ['30' => 30, '365' => 365, 'all' => null];

    /** ?f= : tous, ceux qui ont un bon sur la periode, ceux que Gescomm bloque (CLIBLOQUE / FOURBLOQUE). */
    public const FILTERS = ['all', 'active', 'blocked'];

    /** Prefixe des routes de chaque sorte (customers.index, suppliers.show...). */
    public const ROUTES = ['customer' => 'customers', 'supplier' => 'suppliers'];

    /** Les bons de chaque sorte : ventes pour un client, achats pour un fournisseur. */
    public const DOCUMENT_KIND = ['customer' => 'sale', 'supplier' => 'purchase'];

    private const PER_PAGE = 100;

    /** La fenetre de la fiche : ses bons, ses versements, ses articles, et (lines) un article bon par bon. */
    public const RECORD_TABS = ['bons', 'payments', 'items', 'lines'];

    /** Lignes par page dans cette fenetre. */
    public const RECORD_SIZES = [25, 50, 100];

    /** Colonnes qu'on peut trier ; celles de money_sorts seulement avec les montants (l'ordre trahirait les montants). */
    private const RECORD_SORTS = [
        'bons' => ['date', 'number', 'lines', 'amount'],
        'payments' => ['date', 'amount'],
        'items' => ['amount', 'qty', 'bons', 'last'],
        'lines' => ['date', 'qty', 'price', 'total'],
    ];

    private const MONEY_SORTS = ['amount', 'price', 'total'];

    /** Une impression : toute la liste ou tout le releve, jusqu'a cette limite (annoncee sur la page au-dela). */
    private const PRINT_LIMIT = 5000;

    /** ?show= du releve imprime : tout (bons et versements par date), les bons, les versements, les articles. */
    public const STATEMENT_SHOW = ['all', 'bons', 'payments', 'items'];

    /** ?period= de l'impression des versements. */
    private const PAYMENT_PERIODS = ['1' => 1, '7' => 7, '30' => 30, '365' => 365, 'all' => null];

    public function customers(Request $request): View
    {
        return $this->list($request, 'customer');
    }

    public function suppliers(Request $request): View
    {
        return $this->list($request, 'supplier');
    }

    public function customer(Request $request, Partner $partner): View
    {
        return $this->card($request, 'customer', $partner);
    }

    public function supplier(Request $request, Partner $partner): View
    {
        return $this->card($request, 'supplier', $partner);
    }

    public function customerRecords(Request $request, Partner $partner): View
    {
        return $this->records($request, 'customer', $partner);
    }

    public function supplierRecords(Request $request, Partner $partner): View
    {
        return $this->records($request, 'supplier', $partner);
    }

    public function customersPrint(Request $request): View
    {
        return $this->printList($request, 'customer');
    }

    public function suppliersPrint(Request $request): View
    {
        return $this->printList($request, 'supplier');
    }

    public function customerPrint(Request $request, Partner $partner): View
    {
        return $this->printCard($request, 'customer', $partner);
    }

    public function supplierPrint(Request $request, Partner $partner): View
    {
        return $this->printCard($request, 'supplier', $partner);
    }

    public function customerPaymentsPrint(Request $request): View
    {
        return $this->printPayments($request, 'customer');
    }

    public function supplierPaymentsPrint(Request $request): View
    {
        return $this->printPayments($request, 'supplier');
    }

    private function list(Request $request, string $kind): View
    {
        $money = $this->money($request, $kind);
        $selected = BranchScope::selected($request);
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        // un agent d'avant 1.0.7 n'envoie ni clients ni fournisseurs : sa branche est nommee
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $ids = $ready->pluck('id')->all();
        $period = (string) $request->query('period', '365');
        $period = array_key_exists($period, self::LIST_PERIODS) ? $period : '365';
        $days = self::LIST_PERIODS[$period];
        $since = $days === null ? null : now()->subDays($days - 1)->toDateString();
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $docKind = self::DOCUMENT_KIND[$kind];
        $base = fn (string $f) => $this->people($kind, $ids, $since, null, $q, $f);

        $last = Document::query()->where('kind', $docKind)->whereIn('branch_id', $ids)
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id as l_branch, partner_code as l_code, MAX(date) as last_date');
        // les plus gros d'abord (ou ceux qui ont le plus de bons, sans prices.*), puis par nom
        $partners = $base($filter)
            ->leftJoinSub($last, 'l', fn ($join) => $join->on('l.l_branch', '=', 'partners.branch_id')->on('l.l_code', '=', 'partners.code'))
            ->select(['partners.*', 's.bons', 's.amount', 'l.last_date'])
            ->orderByRaw($money ? 'COALESCE(s.amount, 0) DESC' : 'COALESCE(s.bons, 0) DESC')
            ->orderBy('partners.name')->orderBy('partners.code')
            ->simplePaginate(self::PER_PAGE)->withQueryString();
        $names = $ready->pluck('name', 'id');

        return view('partners.index', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'q' => $q,
            'period' => $period,
            'filter' => $filter,
            'money' => $money,
            'partners' => $partners,
            'rows' => $partners->getCollection()->map(fn (Partner $p) => [
                'url' => route(self::ROUTES[$kind].'.show', $p),
                'name' => $p->name,
                'code' => $p->code,
                'branch' => $names[$p->branch_id] ?? null,
                'hue' => Branch::hueFor($p->branch_id),
                'phone' => $p->phone ?? $p->mobile,
                'wilaya' => $p->wilaya,
                'blocked' => (bool) $p->blocked,
                'bons' => (int) $p->bons,
                'amount' => $money && $p->amount !== null ? round((float) $p->amount, 2) : null,
                'last' => $p->last_date ? Carbon::parse($p->last_date)->format('d/m/Y') : null,
            ]),
            'counts' => collect(self::FILTERS)->mapWithKeys(fn (string $f) => [$f => $base($f)->count()]),
            'branches' => $branches->count(),
            'selected' => $selected,
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }

    /**
     * Impression de la liste : tous ceux de la recherche et du filtre (pas seulement une page), par nom ou par montant,
     * avec leurs bons et leurs versements sur la periode. Memes branches et memes montants que la liste.
     */
    private function printList(Request $request, string $kind): View
    {
        $money = $this->money($request, $kind);
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $ids = $ready->pluck('id')->all();
        $window = PrintPeriod::read($request, self::LIST_PERIODS, '365');
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $sort = $money && $request->query('sort') === 'amount' ? 'amount' : 'name';
        $docKind = self::DOCUMENT_KIND[$kind];

        $last = Document::query()->where('kind', $docKind)->whereIn('branch_id', $ids)
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id as l_branch, partner_code as l_code, MAX(date) as last_date');
        $paid = Payment::query()->where('partner_kind', $kind)->whereIn('branch_id', $ids)
            ->when($window['from'], fn (Builder $b) => $b->where('date', '>=', $window['from']))
            ->when($window['to'], fn (Builder $b) => $b->where('date', '<=', $window['to']))
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id as p_branch, partner_code as p_code, SUM(amount) as paid');
        $base = fn () => $this->people($kind, $ids, $window['from'], $window['to'], $q, $filter);
        $partners = $base()
            ->leftJoinSub($last, 'l', fn ($join) => $join->on('l.l_branch', '=', 'partners.branch_id')->on('l.l_code', '=', 'partners.code'))
            ->leftJoinSub($paid, 'p', fn ($join) => $join->on('p.p_branch', '=', 'partners.branch_id')->on('p.p_code', '=', 'partners.code'))
            ->select(['partners.*', 's.bons', 's.amount', 'l.last_date', 'p.paid'])
            ->when($sort === 'amount', fn (Builder $b) => $b->orderByRaw('COALESCE(s.amount, 0) DESC'))
            ->orderBy('partners.name')->orderBy('partners.code')
            ->limit(self::PRINT_LIMIT)->get();
        $names = $ready->pluck('name', 'id');

        return view('print.partners', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'money' => $money,
            'q' => $q,
            'filter' => $filter,
            'sort' => $sort,
            'window' => $window,
            'rows' => $partners->map(fn (Partner $p) => [
                'name' => $p->name,
                'code' => $p->code,
                'branch' => $names[$p->branch_id] ?? null,
                'phone' => collect([$p->phone, $p->mobile])->filter()->join(' / '),
                'address' => collect([$p->address, $p->wilaya])->filter()->join(' — '),
                'blocked' => (bool) $p->blocked,
                'bons' => (int) $p->bons,
                'amount' => $money && $p->amount !== null ? round((float) $p->amount, 2) : null,
                'paid' => $money && $p->paid !== null ? round((float) $p->paid, 2) : null,
                'last' => $p->last_date ? Carbon::parse($p->last_date)->format('d/m/Y') : null,
            ]),
            'count' => $base()->count(),
            'total' => $money ? round((float) $base()->sum('s.amount'), 2) : null,
            'totalPaid' => $money ? round((float) $base()
                ->leftJoinSub($paid, 'p', fn ($join) => $join->on('p.p_branch', '=', 'partners.branch_id')->on('p.p_code', '=', 'partners.code'))
                ->sum('p.paid'), 2) : null,
            'letter' => $this->letterOf($ready),
            'branchNames' => $ready->pluck('name'),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }

    /**
     * Impression de la fiche : le releve d'un client ou d'un fournisseur sur une periode (bons et versements par date),
     * ou seulement ses bons, ses versements (?show=payments) ou les articles qu'il prend. Sans solde : la facon dont
     * Gescomm le calcule se verifie d'abord (agent/tools/check-balances.ps1).
     */
    private function printCard(Request $request, string $kind, Partner $partner): View
    {
        $branch = $this->cardBranch($request, $kind, $partner);
        $money = $this->money($request, $kind);
        $window = PrintPeriod::read($request, ProductActivity::PERIODS, 'all');
        $show = in_array($request->query('show'), self::STATEMENT_SHOW, true) ? $request->query('show') : 'all';
        $docKind = self::DOCUMENT_KIND[$kind];
        $inWindow = fn (Builder $b) => $b
            ->when($window['from'], fn (Builder $w) => $w->where('date', '>=', $window['from']))
            ->when($window['to'], fn (Builder $w) => $w->where('date', '<=', $window['to']));

        $docs = $inWindow(Document::query()->where('branch_id', $branch->id)->where('kind', $docKind)->where('partner_code', $partner->code));
        $payments = $inWindow(Payment::query()->where('branch_id', $branch->id)->where('partner_kind', $kind)->where('partner_code', $partner->code));
        $sum = (clone $docs)->selectRaw('COUNT(*) as bons, SUM(amount) as amount')->first();
        $paidByType = (clone $payments)->groupBy('type')->selectRaw('type, COUNT(*) as n, SUM(amount) as amount')->orderBy('type')->get();
        $paidCount = (int) $paidByType->sum('n');

        $moves = collect();
        if (in_array($show, ['all', 'bons'], true)) {
            $lines = fn () => DocumentLine::selectRaw('COUNT(*)')->whereColumn('document_lines.document_id', 'documents.id');
            $moves = $moves->concat((clone $docs)->select('documents.*')->selectSub($lines(), 'lines_count')
                ->orderBy('date')->orderBy('time')->orderBy('number')->limit(self::PRINT_LIMIT)->get()
                ->map(fn (Document $d) => [
                    'sort' => ($d->date?->toDateString() ?? '').' '.($d->time ?? ''),
                    'date' => $d->date?->format('d/m/Y'),
                    'time' => $d->time ? substr($d->time, 0, 5) : null,
                    'bon' => true,
                    'type' => null,
                    'number' => $d->number,
                    'lines' => (int) $d->lines_count,
                    'billed' => $money ? $d->amount : null,
                    'paid' => null,
                    'note' => $d->note,
                ]));
        }
        if (in_array($show, ['all', 'payments'], true)) {
            $moves = $moves->concat((clone $payments)->orderBy('date')->orderBy('time')->orderBy('number')->limit(self::PRINT_LIMIT)->get()
                ->map(fn (Payment $p) => [
                    'sort' => ($p->date?->toDateString() ?? '').' '.($p->time ?? ''),
                    'date' => $p->date?->format('d/m/Y'),
                    'time' => $p->time ? substr($p->time, 0, 5) : null,
                    'bon' => false,
                    'type' => $p->type,
                    'number' => $p->document_number ?: null,
                    'lines' => null,
                    'billed' => null,
                    'paid' => $money ? $p->amount : null,
                    'note' => collect([$p->cheque ? __('print.statement.cheque', ['number' => $p->cheque]) : null, $p->note])->filter()->join(' — '),
                ]));
        }

        $items = collect();
        if ($show === 'items') {
            $rows = DocumentLine::whereIn('document_id', (clone $docs)->select('documents.id'))
                ->groupBy('code_key')
                ->selectRaw('code_key, MIN(code_raw) as code, SUM(qty) as qty, SUM(total) as amount, COUNT(*) as n')
                ->orderByDesc($money ? 'amount' : 'qty')
                ->limit(self::PRINT_LIMIT)->get();
            $designations = BranchStock::where('branch_id', $branch->id)->whereIn('code_key', $rows->pluck('code_key'))->pluck('designation', 'code_key');
            $items = $rows->map(fn ($i) => [
                'code' => $i->code,
                'designation' => $designations[$i->code_key] ?? null,
                'qty' => round((float) $i->qty, 2),
                'amount' => $money ? round((float) $i->amount, 2) : null,
                'lines' => (int) $i->n,
            ]);
        }

        return view('print.statement', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'partner' => $partner,
            'branch' => $branch,
            'money' => $money,
            'window' => $window,
            'show' => $show,
            'moves' => $moves->sortBy('sort')->values(),
            'items' => $items,
            'stats' => [
                'bons' => (int) $sum->bons,
                'amount' => $money ? round((float) $sum->amount, 2) : null,
                'payments' => $paidCount,
                'paid' => $money ? round((float) $paidByType->sum('amount'), 2) : null,
            ],
            'capped' => $show !== 'items' && ((int) $sum->bons > self::PRINT_LIMIT || $paidCount > self::PRINT_LIMIT),
            'paidByType' => $money ? $paidByType->map(fn ($t) => ['type' => $t->type, 'n' => (int) $t->n, 'amount' => round((float) $t->amount, 2)]) : collect(),
        ]);
    }

    /**
     * Les versements de tous les clients (ou a tous les fournisseurs) sur une periode, jour par jour, avec le total par
     * type (Espece, Autre comme Gescomm les ecrit). Memes branches et memes montants que les fiches.
     */
    private function printPayments(Request $request, string $kind): View
    {
        $money = $this->money($request, $kind);
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $ids = $ready->pluck('id')->all();
        $window = PrintPeriod::read($request, self::PAYMENT_PERIODS, '30');
        $base = fn () => Payment::query()->where('payments.partner_kind', $kind)->whereIn('payments.branch_id', $ids)
            ->when($window['from'], fn (Builder $b) => $b->where('payments.date', '>=', $window['from']))
            ->when($window['to'], fn (Builder $b) => $b->where('payments.date', '<=', $window['to']));

        $rows = $base()
            ->leftJoin('partners', fn ($join) => $join->on('partners.branch_id', '=', 'payments.branch_id')
                ->on('partners.code', '=', 'payments.partner_code')
                ->where('partners.kind', '=', $kind))
            ->select(['payments.*', 'partners.name as partner_name'])
            ->orderBy('payments.date')->orderBy('payments.time')->orderBy('payments.number')
            ->limit(self::PRINT_LIMIT)->get();
        $days = $base()->groupBy('payments.date')->selectRaw('payments.date as day, COUNT(*) as n, SUM(payments.amount) as amount')->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));
        $byType = $base()->groupBy('payments.type')->selectRaw('payments.type as type, COUNT(*) as n, SUM(payments.amount) as amount')->orderBy('payments.type')->get();
        $names = $ready->pluck('name', 'id');

        return view('print.payments', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'money' => $money,
            'window' => $window,
            'groups' => $rows->groupBy(fn (Payment $p) => $p->date?->toDateString() ?? '')->map(fn ($list, string $day) => [
                'date' => $day === '' ? '—' : Carbon::parse($day)->format('d/m/Y'),
                'name' => $day === '' ? '' : Carbon::parse($day)->locale(app()->getLocale())->translatedFormat('l'),
                'n' => (int) ($days[$day]->n ?? $list->count()),
                'amount' => $money && isset($days[$day]) ? round((float) $days[$day]->amount, 2) : null,
                'rows' => $list->map(fn (Payment $p) => [
                    'time' => $p->time ? substr($p->time, 0, 5) : null,
                    'branch' => $names[$p->branch_id] ?? null,
                    'partner' => $p->partner_name ?? $p->partner_code,
                    'partner_code' => $p->partner_name ? $p->partner_code : null,
                    'type' => $p->type,
                    'cheque' => $p->cheque,
                    'bon' => $p->document_number ?: null,
                    'note' => $p->note,
                    'amount' => $money ? $p->amount : null,
                ]),
            ]),
            'shown' => $rows->count(),
            'count' => (int) $days->sum('n'),
            'total' => $money ? round((float) $days->sum('amount'), 2) : null,
            'byType' => $money ? $byType->map(fn ($t) => ['type' => $t->type, 'n' => (int) $t->n, 'amount' => round((float) $t->amount, 2)]) : collect(),
            'letter' => $this->letterOf($ready),
            'branchNames' => $ready->pluck('name'),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }

    private function card(Request $request, string $kind, Partner $partner): View
    {
        $branch = $this->cardBranch($request, $kind, $partner);
        $money = $this->money($request, $kind);
        $period = $this->cardPeriod($request);
        $since = $this->sinceOf($period);
        $docKind = self::DOCUMENT_KIND[$kind];

        $docs = $this->partnerDocuments($partner, $kind, $since);
        $ids = fn () => (clone $docs)->select('documents.id');
        $sum = (clone $docs)->selectRaw('COUNT(*) as bons, SUM(amount) as amount, MAX(date) as last_date')->first();
        $back = DocumentLine::whereIn('document_id', $ids())->where('qty', '<', 0)->selectRaw('COUNT(*) as n, SUM(total) as amount')->first();

        $payments = $this->partnerPayments($partner, $kind, $since);
        $paidByType = (clone $payments)->groupBy('type')->selectRaw('type, COUNT(*) as n, SUM(amount) as amount')->orderBy('type')->get();
        $lastPayment = (clone $payments)->max('date');

        // ce qu'il prend le plus, net des retours : le bouton des articles le nomme
        $top = DocumentLine::whereIn('document_id', $ids())->groupBy('code_key')
            ->selectRaw('code_key, MIN(code_raw) as code, SUM(qty) as qty, SUM(total) as amount')
            ->orderByDesc($money ? 'amount' : 'qty')->first();
        $topName = $top ? BranchStock::where('branch_id', $branch->id)->where('code_key', $top->code_key)->value('designation') : null;

        return view('partners.show', [
            'kind' => $kind,
            'route' => self::ROUTES[$kind],
            'partner' => $partner,
            'branch' => $branch,
            'money' => $money,
            'period' => $period,
            'stats' => [
                'bons' => (int) $sum->bons,
                'amount' => $money ? round((float) $sum->amount, 2) : null,
                'returns' => (int) $back->n,
                'returns_amount' => $money ? round(-(float) $back->amount, 2) : null,
                'paid' => $money ? round((float) $paidByType->sum('amount'), 2) : null,
                'payments' => (int) $paidByType->sum('n'),
                'last' => $sum->last_date ? Carbon::parse($sum->last_date)->format('d/m/Y') : null,
                'last_payment' => $lastPayment ? Carbon::parse($lastPayment)->format('d/m/Y') : null,
                'items' => DocumentLine::whereIn('document_id', $ids())->distinct()->count('code_key'),
                'top_item' => $top ? ($topName ?? $top->code) : null,
            ],
            'paidByType' => $money ? $paidByType->map(fn ($t) => ['type' => $t->type, 'n' => (int) $t->n, 'amount' => round((float) $t->amount, 2)]) : collect(),
            'insights' => PartnerInsights::for($partner, $money, $money && $kind === 'customer' && $request->user()->can('prices.buy')),
            'docRoute' => DocumentController::ROUTES[$docKind],
        ]);
    }

    /**
     * Une page de la fenetre de la fiche (partners/_records, ?tab=) : memes branche, periode et montants que la fiche.
     * Recherche (?q=), filtre (?f=), tri (?sort= &dir=), taille (?per=). Plusieurs mots : chacun doit se trouver.
     */
    private function records(Request $request, string $kind, Partner $partner): View
    {
        $branch = $this->cardBranch($request, $kind, $partner);
        $money = $this->money($request, $kind);
        $tab = in_array($request->query('tab'), self::RECORD_TABS, true) ? $request->query('tab') : 'bons';
        $per = in_array((int) $request->query('per'), self::RECORD_SIZES, true) ? (int) $request->query('per') : self::RECORD_SIZES[0];
        $sorts = array_values(array_filter(self::RECORD_SORTS[$tab], fn (string $s) => $money || ! in_array($s, self::MONEY_SORTS, true)));
        $default = $tab === 'items' ? $sorts[0] : 'date';
        $asked = $request->query('sort');
        $sort = in_array($asked, $sorts, true) ? $asked : $default;
        $context = [
            'kind' => $kind,
            'partner' => $partner,
            'branch' => $branch,
            'money' => $money,
            'since' => $this->sinceOf($this->cardPeriod($request)),
            'q' => trim(mb_substr((string) $request->query('q', ''), 0, 60)),
            'f' => mb_substr((string) $request->query('f', ''), 0, 20),
            'per' => $per,
            'sort' => $sort,
            'dir' => $asked === $sort && $request->query('dir') === 'asc' ? 'asc' : 'desc',
            'seesStock' => $request->user()->seesBranch($branch),
            'route' => DocumentController::ROUTES[self::DOCUMENT_KIND[$kind]],
        ];

        $data = match ($tab) {
            'payments' => $this->paymentRecords($context),
            'items' => $this->itemRecords($context),
            'lines' => $this->lineRecords($context, mb_substr((string) $request->query('code', ''), 0, 30)),
            default => $this->bonRecords($context),
        };

        return view('partners._records', $context + $data + ['tab' => $tab, 'sorts' => $sorts]);
    }

    private function bonRecords(array $c): array
    {
        $base = $this->partnerDocuments($c['partner'], $c['kind'], $c['since'])
            ->when($c['q'] !== '', function (Builder $b) use ($c) {
                foreach ($this->words($c['q']) as $like) {
                    $b->where(fn (Builder $w) => $w->whereRaw("documents.number LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("UPPER(documents.note) LIKE ? ESCAPE '!'", [$like]));
                }
            });
        $withReturns = fn (Builder $b) => $b->whereExists(fn ($s) => $s->selectRaw('1')->from('document_lines')
            ->whereColumn('document_lines.document_id', 'documents.id')->where('document_lines.qty', '<', 0));
        $returning = $withReturns(clone $base)->count();
        $filter = $c['f'] === 'returns' ? 'returns' : '';
        $rowsQuery = (clone $base)->when($filter === 'returns', $withReturns);
        $sum = (clone $rowsQuery)->selectRaw('COUNT(*) as n, SUM(documents.amount) as amount, SUM(documents.discount) as discount')->first();

        $lines = fn () => DocumentLine::selectRaw('COUNT(*)')->whereColumn('document_lines.document_id', 'documents.id');
        $page = $rowsQuery->select('documents.*')
            ->selectSub($lines(), 'lines_count')
            ->selectSub($lines()->where('document_lines.qty', '<', 0), 'returns_count')
            ->when($c['sort'] === 'date', fn (Builder $b) => $b->orderBy('documents.date', $c['dir'])->orderBy('documents.time', $c['dir']))
            ->when($c['sort'] === 'lines', fn (Builder $b) => $b->orderBy('lines_count', $c['dir']))
            ->when($c['sort'] === 'amount', fn (Builder $b) => $b->orderBy('documents.amount', $c['dir']))
            ->orderBy('documents.number', $c['dir'])->orderBy('documents.id', $c['dir'])
            ->paginate($c['per']);

        return [
            'page' => $page,
            'rows' => $page->getCollection()->map(fn (Document $d) => [
                'url' => route("{$c['route']}.show", $d),
                'number' => $d->number,
                'date' => $d->date?->format('d/m/Y'),
                'time' => $d->time ? substr($d->time, 0, 5) : null,
                'lines' => (int) $d->lines_count,
                'returns' => (int) $d->returns_count,
                'payment' => $d->payment,
                'served_by' => $d->served_by,
                'note' => $d->note,
                'discount' => $c['money'] ? $d->discount : null,
                'amount' => $c['money'] ? $d->amount : null,
            ]),
            'filter' => $filter,
            'filters' => ['' => null, 'returns' => $returning],
            'summary' => [
                'n' => (int) $sum->n,
                'amount' => $c['money'] ? round((float) $sum->amount, 2) : null,
                'discount' => $c['money'] && (float) $sum->discount != 0 ? round((float) $sum->discount, 2) : null,
            ],
        ];
    }

    private function paymentRecords(array $c): array
    {
        $base = $this->partnerPayments($c['partner'], $c['kind'], $c['since'])
            ->when($c['q'] !== '', function (Builder $b) use ($c) {
                foreach ($this->words($c['q']) as $like) {
                    $b->where(function (Builder $w) use ($c, $like) {
                        $w->whereRaw("UPPER(note) LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("UPPER(cheque) LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("document_number LIKE ? ESCAPE '!'", [$like]);
                        // chercher un montant : seulement si le compte voit les montants
                        $amount = str_replace([' ', ','], ['', '.'], $c['q']);
                        if ($c['money'] && is_numeric($amount)) {
                            $w->orWhere('amount', (float) $amount);
                        }
                    });
                }
            });
        $types = (clone $base)->groupBy('type')->selectRaw('type, COUNT(*) as n')->orderByDesc('n')->orderBy('type')->get();
        $filter = $types->contains(fn ($t) => (string) $t->type === $c['f']) && $c['f'] !== '' ? $c['f'] : '';
        $rowsQuery = (clone $base)->when($filter !== '', fn (Builder $b) => $b->where('type', $filter));
        $sum = (clone $rowsQuery)->selectRaw('COUNT(*) as n, SUM(amount) as amount')->first();

        $page = $rowsQuery
            ->when($c['sort'] === 'date', fn (Builder $b) => $b->orderBy('date', $c['dir'])->orderBy('time', $c['dir']))
            ->when($c['sort'] === 'amount', fn (Builder $b) => $b->orderBy('amount', $c['dir']))
            ->orderBy('number', $c['dir'])->orderBy('id', $c['dir'])
            ->paginate($c['per']);
        // le bon d'un versement (NUMEROBON), s'il est arrive
        $paidBons = Document::where('branch_id', $c['branch']->id)->where('kind', self::DOCUMENT_KIND[$c['kind']])->where('partner_code', $c['partner']->code)
            ->whereIn('number', $page->getCollection()->pluck('document_number')->filter()->unique())->pluck('id', 'number');

        return [
            'page' => $page,
            'rows' => $page->getCollection()->map(fn (Payment $p) => [
                'date' => $p->date?->format('d/m/Y'),
                'time' => $p->time ? substr($p->time, 0, 5) : null,
                'number' => $p->number,
                'type' => $p->type,
                'amount' => $c['money'] ? $p->amount : null,
                'cheque' => $p->cheque,
                'paid_on' => $p->paid_on?->format('d/m/Y'),
                'bon' => $p->document_number,
                'bon_url' => isset($paidBons[$p->document_number]) ? route("{$c['route']}.show", $paidBons[$p->document_number]) : null,
                'note' => $p->note,
                'entered_by' => $p->entered_by,
            ]),
            'filter' => $filter,
            'filters' => $types->filter(fn ($t) => (string) $t->type !== '')->mapWithKeys(fn ($t) => [(string) $t->type => (int) $t->n])->prepend(null, '')->all(),
            'summary' => ['n' => (int) $sum->n, 'amount' => $c['money'] ? round((float) $sum->amount, 2) : null],
        ];
    }

    private function itemRecords(array $c): array
    {
        $docs = fn () => $this->partnerDocuments($c['partner'], $c['kind'], $c['since'])->select('documents.id');
        $base = DocumentLine::whereIn('document_id', $docs())
            ->when($c['q'] !== '', function (Builder $b) use ($c) {
                foreach ($this->words($c['q']) as $like) {
                    $named = BranchStock::where('branch_id', $c['branch']->id)->whereRaw("UPPER(designation) LIKE ? ESCAPE '!'", [$like])->select('code_key');
                    $b->where(fn (Builder $w) => $w->whereRaw("code_key LIKE ? ESCAPE '!'", [$like])->orWhereIn('code_key', $named));
                }
            });
        $sum = (clone $base)->selectRaw('COUNT(DISTINCT code_key) as n, SUM(total) as amount')->first();
        $column = ['amount' => 'amount', 'qty' => 'qty', 'bons' => 'bons', 'last' => 'last_date'][$c['sort']];

        $page = (clone $base)->groupBy('code_key')
            ->selectRaw('code_key, MIN(code_raw) as code, SUM(qty) as qty, SUM(total) as amount,
                SUM(CASE WHEN qty > 0 THEN qty ELSE 0 END) as sold, SUM(CASE WHEN qty < 0 THEN -qty ELSE 0 END) as returned,
                COUNT(DISTINCT document_id) as bons, MAX(date) as last_date')
            ->orderBy($column, $c['dir'])->orderBy('code_key')
            ->paginate($c['per']);
        $stock = BranchStock::where('branch_id', $c['branch']->id)->whereIn('code_key', $page->getCollection()->pluck('code_key'))
            ->get(['code_key', 'designation', 'stock', 'stock_alert', 'stock_max'])->keyBy('code_key');

        return [
            'page' => $page,
            'rows' => $page->getCollection()->map(function ($i) use ($c, $stock) {
                $row = $stock[$i->code_key] ?? null;
                $qty = round((float) $i->qty, 2);

                return [
                    'code' => $i->code,
                    'key' => $i->code_key,
                    'designation' => $row?->designation,
                    'sold' => round((float) $i->sold, 2),
                    'returned' => round((float) $i->returned, 2),
                    'qty' => $qty,
                    'amount' => $c['money'] ? round((float) $i->amount, 2) : null,
                    'average' => $c['money'] && abs($qty) > 0.005 ? round((float) $i->amount / $qty, 2) : null,
                    'bons' => (int) $i->bons,
                    'last' => $i->last_date ? Carbon::parse($i->last_date)->format('d/m/Y') : null,
                    'stock' => $c['seesStock'] && $row ? (float) $row->stock : null,
                    'state' => $c['seesStock'] && $row ? StockState::of((float) $row->stock, $row->stock_alert, $row->stock_max) : null,
                ];
            }),
            'filter' => '',
            'filters' => [],
            'summary' => ['n' => (int) $sum->n, 'amount' => $c['money'] ? round((float) $sum->amount, 2) : null],
        ];
    }

    /** Un article, bon par bon : quand, combien, a quel prix. La question du comptoir : "a combien je le lui ai fait ?" */
    private function lineRecords(array $c, string $code): array
    {
        $key = mb_strtoupper(trim($code));
        $base = DocumentLine::query()->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.branch_id', $c['branch']->id)
            ->where('documents.kind', self::DOCUMENT_KIND[$c['kind']])
            ->where('documents.partner_code', $c['partner']->code)
            ->where('document_lines.code_key', $key)
            ->when($c['since'], fn (Builder $b) => $b->where('documents.date', '>=', $c['since']));
        $sum = (clone $base)->selectRaw('COUNT(*) as n, SUM(document_lines.qty) as qty, SUM(document_lines.total) as amount,
            SUM(CASE WHEN document_lines.qty > 0 THEN document_lines.qty ELSE 0 END) as sold,
            SUM(CASE WHEN document_lines.qty < 0 THEN -document_lines.qty ELSE 0 END) as returned')->first();
        $lastPrice = (clone $base)->where('document_lines.qty', '>', 0)->orderByDesc('documents.date')->orderByDesc('documents.time')->orderByDesc('documents.number')->value('document_lines.price');
        $column = ['qty' => 'document_lines.qty', 'price' => 'document_lines.price', 'total' => 'document_lines.total'][$c['sort']] ?? null;
        $item = BranchStock::where('branch_id', $c['branch']->id)->where('code_key', $key)->first(['designation', 'code_raw']);

        $page = (clone $base)->select(['document_lines.*', 'documents.number as bon_number', 'documents.time as bon_time', 'documents.id as bon_id'])
            ->when($column === null, fn (Builder $b) => $b->orderBy('documents.date', $c['dir'])->orderBy('documents.time', $c['dir']))
            ->when($column !== null, fn (Builder $b) => $b->orderBy($column, $c['dir']))
            ->orderBy('documents.number', $c['dir'])->orderBy('document_lines.position')
            ->paginate($c['per']);
        $qty = round((float) $sum->qty, 2);

        return [
            'page' => $page,
            'rows' => $page->getCollection()->map(fn (DocumentLine $l) => [
                'url' => route("{$c['route']}.show", $l->bon_id),
                'number' => $l->bon_number,
                'date' => $l->date?->format('d/m/Y'),
                'time' => $l->bon_time ? substr($l->bon_time, 0, 5) : null,
                'qty' => $l->qty,
                'back' => (float) $l->qty < 0,
                'price' => $c['money'] ? $l->price : null,
                'discount' => $c['money'] ? $l->discount : null,
                'total' => $c['money'] ? $l->total : null,
            ]),
            'filter' => '',
            'filters' => [],
            'item' => ['code' => $item?->code_raw ?? $code, 'key' => $key, 'designation' => $item?->designation],
            'summary' => [
                'n' => (int) $sum->n,
                'sold' => round((float) $sum->sold, 2),
                'returned' => round((float) $sum->returned, 2),
                'qty' => $qty,
                'amount' => $c['money'] ? round((float) $sum->amount, 2) : null,
                'average' => $c['money'] && abs($qty) > 0.005 ? round((float) $sum->amount / $qty, 2) : null,
                'last_price' => $c['money'] && $lastPrice !== null ? (float) $lastPrice : null,
            ],
        ];
    }

    private function cardPeriod(Request $request): string
    {
        $period = (string) $request->query('period', 'all');

        return array_key_exists($period, ProductActivity::PERIODS) ? $period : 'all';
    }

    private function sinceOf(string $period): ?string
    {
        $days = ProductActivity::PERIODS[$period];

        return $days === null ? null : now()->subDays($days - 1)->toDateString();
    }

    private function partnerDocuments(Partner $partner, string $kind, ?string $since): Builder
    {
        return Document::query()->where('documents.branch_id', $partner->branch_id)->where('documents.kind', self::DOCUMENT_KIND[$kind])
            ->where('documents.partner_code', $partner->code)
            ->when($since, fn (Builder $b) => $b->where('documents.date', '>=', $since));
    }

    private function partnerPayments(Partner $partner, string $kind, ?string $since): Builder
    {
        return Payment::query()->where('branch_id', $partner->branch_id)->where('partner_kind', $kind)->where('partner_code', $partner->code)
            ->when($since, fn (Builder $b) => $b->where('date', '>=', $since));
    }

    /** @return list<string> un motif LIKE (ESCAPE '!') par mot cherche */
    private function words(string $q): array
    {
        return array_map(
            fn (string $word) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtoupper($word)).'%',
            preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    /** La branche d'une fiche ; une fiche d'une branche dont le compte ne voit pas les ventes n'existe pas pour lui. */
    private function cardBranch(Request $request, string $kind, Partner $partner): Branch
    {
        $allowed = $request->user()->salesBranchIds();
        $branch = $partner->branch;
        abort_unless($partner->kind === $kind && $branch?->active
            && ($allowed === null || in_array($partner->branch_id, $allowed, true)), 404);

        return $branch;
    }

    /** Clients ou fournisseurs de ces branches, la recherche et le filtre, avec leurs bons (s.bons, s.amount) sur la periode. */
    private function people(string $kind, array $ids, ?string $from, ?string $to, string $q, string $filter): Builder
    {
        $stats = Document::query()->where('kind', self::DOCUMENT_KIND[$kind])->whereIn('branch_id', $ids)
            ->when($from, fn (Builder $b) => $b->where('date', '>=', $from))
            ->when($to, fn (Builder $b) => $b->where('date', '<=', $to))
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id as s_branch, partner_code as s_code, COUNT(*) as bons, SUM(amount) as amount');

        return Partner::query()
            ->where('partners.kind', $kind)
            ->whereIn('partners.branch_id', $ids)
            ->leftJoinSub($stats, 's', fn ($join) => $join->on('s.s_branch', '=', 'partners.branch_id')->on('s.s_code', '=', 'partners.code'))
            ->when($q !== '', function (Builder $b) use ($q) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtoupper($q)).'%';
                $b->where(fn (Builder $w) => $w->whereIn('partners.code', array_unique([$q, mb_strtoupper($q)]))
                    ->orWhereRaw("UPPER(partners.name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("partners.phone LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("partners.mobile LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($filter === 'active', fn (Builder $b) => $b->whereNotNull('s.bons'))
            ->when($filter === 'blocked', fn (Builder $b) => $b->where('partners.blocked', true));
    }

    /** L'en-tete d'un etat : celui de la branche s'il n'y en a qu'une, sinon de la principale si elle en est. */
    private function letterOf($branches): ?Branch
    {
        return $branches->count() === 1 ? $branches->first() : $branches->firstWhere('is_main', true);
    }

    /** Les montants d'une sorte : prix de vente pour les clients, prix d'achat pour les fournisseurs. */
    private function money(Request $request, string $kind): bool
    {
        return $request->user()->can($kind === 'customer' ? 'prices.sale' : 'prices.buy');
    }
}
