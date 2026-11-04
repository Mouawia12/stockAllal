<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Support\BranchScope;
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

        $stats = Document::query()->where('kind', $docKind)->whereIn('branch_id', $ids)
            ->when($since, fn (Builder $b) => $b->where('date', '>=', $since))
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id as s_branch, partner_code as s_code, COUNT(*) as bons, SUM(amount) as amount');
        $base = fn (string $f) => Partner::query()
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
            ->when($f === 'active', fn (Builder $b) => $b->whereNotNull('s.bons'))
            ->when($f === 'blocked', fn (Builder $b) => $b->where('partners.blocked', true));

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

    private function card(Request $request, string $kind, Partner $partner): View
    {
        $allowed = $request->user()->salesBranchIds();
        $branch = $partner->branch;
        // une fiche d'une branche dont le compte ne voit pas les ventes n'existe pas pour lui
        abort_unless($partner->kind === $kind && $branch?->active
            && ($allowed === null || in_array($partner->branch_id, $allowed, true)), 404);

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

    /** Les montants d'une sorte : prix de vente pour les clients, prix d'achat pour les fournisseurs. */
    private function money(Request $request, string $kind): bool
    {
        return $request->user()->can($kind === 'customer' ? 'prices.sale' : 'prices.buy');
    }
}
