<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Support\BranchScope;
use App\Support\PrintPeriod;
use App\Support\ProductActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Clients (GC002) et fournisseurs (GC004) venus de Gescomm (agent 1.0.7), facon Odoo : la liste (recherche, ce que
 * chacun a achete sur la periode, actifs, bloques), puis la fiche : coordonnees, bons, versements, articles.
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

    private const CARD_LIMIT = 50;

    private const TOP_ITEMS = 20;

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
        $period = (string) $request->query('period', 'all');
        $period = array_key_exists($period, ProductActivity::PERIODS) ? $period : 'all';
        $days = ProductActivity::PERIODS[$period];
        $since = $days === null ? null : now()->subDays($days - 1)->toDateString();
        $docKind = self::DOCUMENT_KIND[$kind];

        $docs = Document::query()->where('documents.branch_id', $branch->id)->where('documents.kind', $docKind)
            ->where('documents.partner_code', $partner->code)
            ->when($since, fn (Builder $b) => $b->where('documents.date', '>=', $since));
        $ids = fn () => (clone $docs)->select('documents.id');
        $sum = (clone $docs)->selectRaw('COUNT(*) as bons, SUM(amount) as amount, MAX(date) as last_date')->first();
        $back = DocumentLine::whereIn('document_id', $ids())->where('qty', '<', 0)->selectRaw('COUNT(*) as n, SUM(total) as amount')->first();

        $payments = Payment::query()->where('branch_id', $branch->id)->where('partner_kind', $kind)->where('partner_code', $partner->code)
            ->when($since, fn (Builder $b) => $b->where('date', '>=', $since));
        $paidByType = (clone $payments)->groupBy('type')->selectRaw('type, COUNT(*) as n, SUM(amount) as amount')->orderBy('type')->get();

        $lines = fn () => DocumentLine::selectRaw('COUNT(*)')->whereColumn('document_lines.document_id', 'documents.id');
        $bons = (clone $docs)->select('documents.*')
            ->selectSub($lines(), 'lines_count')
            ->selectSub($lines()->where('document_lines.qty', '<', 0), 'returns_count')
            ->orderByDesc('documents.date')->orderByDesc('documents.time')->orderByDesc('documents.number')
            ->limit(self::CARD_LIMIT)->get();
        $paymentRows = (clone $payments)->orderByDesc('date')->orderByDesc('time')->orderByDesc('number')->limit(self::CARD_LIMIT)->get();
        // le bon d'un versement (NUMEROBON), s'il est arrive
        $paidBons = Document::where('branch_id', $branch->id)->where('kind', $docKind)->where('partner_code', $partner->code)
            ->whereIn('number', $paymentRows->pluck('document_number')->filter()->unique())->pluck('id', 'number');

        // ce qu'il achete (ou ce qu'on lui achete) le plus, net des retours
        $items = DocumentLine::whereIn('document_id', $ids())
            ->groupBy('code_key')
            ->selectRaw('code_key, MIN(code_raw) as code, SUM(qty) as qty, SUM(total) as amount, COUNT(*) as n')
            ->orderByDesc($money ? 'amount' : 'qty')
            ->limit(self::TOP_ITEMS)->get();
        $designations = BranchStock::where('branch_id', $branch->id)->whereIn('code_key', $items->pluck('code_key'))->pluck('designation', 'code_key');
        $route = DocumentController::ROUTES[$docKind];

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
            ],
            'paidByType' => $money ? $paidByType->map(fn ($t) => ['type' => $t->type, 'n' => (int) $t->n, 'amount' => round((float) $t->amount, 2)]) : collect(),
            'bons' => $bons->map(fn (Document $d) => [
                'url' => route("{$route}.show", $d),
                'number' => $d->number,
                'date' => $d->date?->format('d/m/Y'),
                'time' => $d->time ? substr($d->time, 0, 5) : null,
                'lines' => (int) $d->lines_count,
                'returns' => (int) $d->returns_count,
                'amount' => $money ? $d->amount : null,
            ]),
            'payments' => $paymentRows->map(fn (Payment $p) => [
                'date' => $p->date?->format('d/m/Y'),
                'time' => $p->time ? substr($p->time, 0, 5) : null,
                'type' => $p->type,
                'amount' => $money ? $p->amount : null,
                'cheque' => $p->cheque,
                'paid_on' => $p->paid_on?->format('d/m/Y'),
                'bon' => $p->document_number,
                'bon_url' => isset($paidBons[$p->document_number]) ? route("{$route}.show", $paidBons[$p->document_number]) : null,
                'note' => $p->note,
            ]),
            'items' => $items->map(fn ($i) => [
                'code' => $i->code,
                'designation' => $designations[$i->code_key] ?? null,
                'qty' => round((float) $i->qty, 2),
                'amount' => $money ? round((float) $i->amount, 2) : null,
                'lines' => (int) $i->n,
            ]),
            'docRoute' => $route,
        ]);
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
