<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchScope;
use App\Support\CashBox;
use App\Support\SalesDashboard;
use App\Support\StockState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * "لوحة القيادة" : ou en sont les ventes et les articles, sur une periode, dans les branches dont le compte voit les
 * ventes (BranchScope::salesIds, comme les bons). Les chiffres viennent de SalesDashboard ; ici on retire ce que le
 * compte ne peut pas voir : montants de vente sans prices.sale (il voit alors des bons et des quantites), marge,
 * achats et valeur du stock sans prices.buy, stock d'une branche dont il ne voit pas le stock.
 * La periode : un bouton (aujourd'hui, 7, 30 jours, 12 mois) ou un jour / des jours choisis dans le calendrier
 * (calendar(), le JSON d'un mois). La caisse (CashBox) : seulement avec cash.view, dans les memes branches.
 */
class DashboardController extends Controller
{
    public const RANKS = ['amount', 'qty'];

    private const LIST = 8;

    public function index(Request $request): View
    {
        $user = $request->user();
        $sale = $user->can('prices.sale');
        $buy = $user->can('prices.buy');
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        // un agent d'avant 1.0.7 n'envoie pas les ventes : sa branche est nommee, jamais comptee comme "zero vente"
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $period = SalesDashboard::period($request->query(), now());
        $kind = SalesDashboard::kind($period);
        $window = SalesDashboard::window($period, now());
        $rank = $sale && $request->query('rank') !== 'qty' ? 'amount' : 'qty';
        $rankQuery = $rank === 'qty' && $sale ? 'qty' : null;

        $page = [
            'period' => $period,
            'kind' => $kind,
            'window' => $window,
            // la periode dans l'adresse, gardee par les autres liens de la page (classement)
            'here' => match ($kind) {
                'day' => ['day' => $window['from']],
                'range' => ['from' => $window['from'], 'to' => $window['to']],
                default => ['period' => $period === '30' ? null : $period],
            },
            'date' => $this->dateNav($kind, $window, $rankQuery),
            'rank' => $rank,
            'money' => $sale,
            'buy' => $buy,
            'selected' => BranchScope::selected($request),
            'branches' => $branches->count(),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
            'cash' => $user->can('cash.view') ? $this->cash($branches, $window) : null,
        ];
        if ($ready->isEmpty()) {
            return view('dashboard.index', $page + ['f' => null]);
        }

        $f = SalesDashboard::figures($ready->pluck('id')->all(), $period);
        $t = $f['totals'];
        $names = $ready->pluck('name', 'id');
        $stockIds = $user->stockBranchIds();
        $sees = fn (int $id) => $stockIds === null || in_array($id, $stockIds, true);
        $branch = fn (int $id) => ['branch' => $names[$id] ?? null, 'hue' => Branch::hueFor($id)];
        $metric = $sale ? 'amount' : 'bons';

        // la courbe : chaque heure, jour ou mois de la fenetre ; la derniere case = maintenant
        $series = collect($f['series'])->map(fn (array $p) => $this->point($p, $f['window']['unit'], $sale));
        $max = max(1, (float) $series->max('value'));
        $last = $series->keys()->last();
        $series = $series->map(fn (array $p, int $i) => $p + ['pct' => round($p['value'] / $max * 100, 1), 'now' => $i === $last]);
        $peak = $series->where('value', '>', 0)->sortByDesc('value')->first();

        $perBranch = collect($f['branches'])->map(fn (array $b, int $id) => $branch($id) + [
            'value' => $b[$metric],
            'trend' => SalesDashboard::trend($b[$metric], $b['prev_'.$metric]),
        ])->sortByDesc('value')->values();
        $branchTotal = max(0.01, (float) $perBranch->sum('value'));
        $perBranch = $perBranch->map(fn (array $b) => $b + ['share' => round(max(0, $b['value']) / $branchTotal * 100, 1)]);

        $products = collect($f['products'][$rank === 'amount' ? 'by_amount' : 'by_qty']);
        $productMax = max(0.01, (float) $products->max($rank === 'amount' ? 'amount' : 'net'));
        $products = $products->map(function (array $p) use ($sale, $buy, $sees, $rank, $productMax) {
            $seen = collect($p['stock'])->filter(fn ($qty, $id) => $sees((int) $id));
            $stock = $seen->isEmpty() ? null : round((float) $seen->sum(), 2);

            return [
                'url' => route('stock.item', ['code' => $p['key']]),
                'code' => $p['code'],
                'designation' => $p['designation'],
                'net' => $p['net'],
                'amount' => $sale ? $p['amount'] : null,
                // marge en % du montant des lignes qui ont un prix d'achat (PRIXACHAT du jour de la vente)
                'margin' => $buy && abs($p['costed_amount']) >= 0.005 ? round($p['margin'] / $p['costed_amount'] * 100, 1) : null,
                'margin_partial' => $buy && $p['without_cost'] > 0,
                'stock' => $stock,
                'state' => StockState::of($stock),
                'bar' => round(max(0, $rank === 'amount' ? $p['amount'] : $p['net']) / $productMax * 100, 1),
            ];
        });

        $customers = collect($f['customers'][$sale ? 'by_amount' : 'by_bons'])->map(fn (array $c) => $branch($c['branch_id']) + [
            'name' => $c['name'] ?? $c['code'],
            'code' => $c['name'] ? $c['code'] : null,
            'url' => $c['id'] ? route('customers.show', $c['id']) : null,
            'bons' => $c['bons'],
            'amount' => $sale ? $c['amount'] : null,
        ]);

        $soldOut = collect(SalesDashboard::onlyBranches($f['sold_out'], $stockIds))->take(self::LIST)
            ->map(fn (array $r) => $r + $branch($r['branch_id']) + ['url' => route('stock.item', ['code' => $r['key']]), 'state' => StockState::of($r['stock'])]);
        $dormantBranches = collect($f['dormant']['per_branch'])->filter(fn ($d, $id) => $sees((int) $id));
        $dormant = [
            'count' => (int) $dormantBranches->sum('count'),
            'value' => $buy ? round((float) $dormantBranches->sum('value'), 2) : null,
            'items' => collect(SalesDashboard::onlyBranches($f['dormant']['items'], $stockIds))->take(self::LIST)
                ->map(fn (array $r) => array_merge($r, $branch($r['branch_id']), [
                    'url' => route('stock.item', ['code' => $r['key']]),
                    'value' => $buy ? $r['value'] : null,
                ])),
            // le compte voit-il le stock d'au moins une de ces branches ? (sinon la carte n'a rien a dire)
            'seen' => $ready->contains(fn (Branch $b) => $sees($b->id)),
        ];

        $hours = collect($f['hours']);
        $hourMax = max(1, (int) $hours->max());

        return view('dashboard.index', $page + [
            'f' => $f,
            'totals' => [
                'amount' => $sale ? $t['amount'] : null,
                'bons' => $t['bons'],
                'average' => $sale && $t['bons'] > 0 ? round($t['amount'] / $t['bons'], 2) : null,
                'products' => $t['products'],
                'back_amount' => $sale ? $t['back_amount'] : null,
                'back_qty' => $t['back_qty'],
                'back_lines' => $t['back_lines'],
                'margin' => $buy && $t['bons'] > 0 ? $t['margin'] : null,
                'margin_pct' => $buy && abs($t['costed_amount']) >= 0.005 ? round($t['margin'] / $t['costed_amount'] * 100, 1) : null,
                'margin_partial' => $t['without_cost'] > 0,
                'purchase_amount' => $buy ? $t['purchase_amount'] : null,
                'purchase_bons' => $t['purchase_bons'],
            ],
            'trends' => [
                'amount' => SalesDashboard::trend($t['amount'], $t['prev_amount']),
                'bons' => SalesDashboard::trend($t['bons'], $t['prev_bons']),
                'margin' => SalesDashboard::trend($t['margin'], $t['prev_margin']),
            ],
            'series' => $series->values(),
            'peak' => $peak,
            'perBranch' => $perBranch,
            'products' => $products,
            'customers' => $customers,
            'soldOut' => $soldOut,
            'dormant' => $dormant,
            'hours' => $hours->isEmpty() ? collect() : collect(range($hours->keys()->min(), $hours->keys()->max()))
                ->map(fn (int $h) => ['hour' => $h, 'bons' => (int) ($hours[$h] ?? 0), 'pct' => round(($hours[$h] ?? 0) / $hourMax * 100, 1)]),
            'busiest' => $hours->isEmpty() ? null : $hours->sortDesc()->keys()->first(),
            'syncedAt' => $f['synced_at'] ? Carbon::parse($f['synced_at']) : null,
        ]);
    }

    /**
     * Le calendrier de "لوحة القيادة", un mois (?month=2026-11, le mois en cours par defaut, jamais un mois a venir) :
     * chaque jour avec ses ventes (le montant avec prices.sale, sinon les bons) et sa caisse (cash.view), dans les
     * memes branches que la page. La semaine commence le samedi, comme les calendriers algeriens.
     */
    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->startOfDay();
        $asked = $request->query('month');
        $month = is_string($asked) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asked) ? Carbon::parse($asked.'-01') : $today->copy()->startOfMonth();
        if ($month->gt($today)) {
            $month = $today->copy()->startOfMonth();
        }
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->get();
        $salesIds = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->pluck('id')->values()->all();
        $cashIds = $user->can('cash.view') ? $branches->filter(fn (Branch $b) => $b->agentSupports('cash'))->pluck('id')->values()->all() : [];
        $sale = $user->can('prices.sale');
        $days = $salesIds === [] ? [] : SalesDashboard::days($salesIds, $from, $to);
        $cash = $cashIds === [] ? [] : CashBox::days($cashIds, $from, $to);
        $first = $salesIds === [] ? null : SalesDashboard::firstDay($salesIds);
        $locale = app()->getLocale();

        $list = [];
        for ($d = $month->copy(); $d->toDateString() <= $to; $d->addDay()) {
            $key = $d->toDateString();
            $list[] = [
                'date' => $key,
                'label' => $d->copy()->locale($locale)->translatedFormat('l j F Y'),
                'future' => $d->gt($today),
                'bons' => $days[$key]['bons'] ?? 0,
                'amount' => $sale ? ($days[$key]['amount'] ?? 0.0) : null,
                'cash_in' => $cashIds === [] ? null : ($cash[$key]['in'] ?? 0.0),
                'cash_out' => $cashIds === [] ? null : ($cash[$key]['out'] ?? 0.0),
            ];
        }

        return response()->json([
            'month' => $month->format('Y-m'),
            'label' => $month->copy()->locale($locale)->translatedFormat('F Y'),
            'today' => $today->toDateString(),
            // pas avant le mois de la premiere vente, pas apres le mois en cours
            'prev' => $first === null || $first >= $from ? null : $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->gt($today) ? null : $month->copy()->addMonth()->format('Y-m'),
            // cases vides avant le 1er : la semaine commence le samedi (dayOfWeek : 0 = dimanche, 6 = samedi)
            'offset' => ($month->dayOfWeek + 1) % 7,
            'money' => $sale,
            'cash' => $cashIds !== [],
            'days' => $list,
            'total' => ['amount' => $sale ? round(array_sum(array_column($days, 'amount')), 2) : null, 'bons' => array_sum(array_column($days, 'bons'))],
        ]);
    }

    /**
     * Le bouton du calendrier : ce qui est choisi (un jour, des jours), la veille et le lendemain d'un jour, et les
     * choix rapides (hier, cette semaine, ce mois, le mois dernier).
     *
     * @return array{label: ?string, month: string, prev: ?string, next: ?string, picks: list<array{label: string, url: string, on: bool}>}
     */
    private function dateNav(string $kind, array $w, ?string $rank): array
    {
        $locale = app()->getLocale();
        $today = now()->startOfDay();
        $url = fn (array $q) => route('dashboard.index', array_filter($q + ['rank' => $rank]));
        // l'annee seulement quand ce n'est pas celle-ci
        $short = fn (Carbon $d) => $d->format($d->year === $today->year ? 'd/m' : 'd/m/Y');
        [$from, $to] = [Carbon::parse($w['from']), Carbon::parse($w['to'])];

        $label = match ($kind) {
            'day' => $from->copy()->locale($locale)->translatedFormat('l').' '.$short($from),
            'range' => $short($from).' – '.$short($to),
            default => null,
        };
        $prev = $next = null;
        if ($kind === 'day' || $kind === 'today') {
            $prev = $url(['day' => $from->copy()->subDay()->toDateString()]);
            $after = $from->copy()->addDay();
            $next = $kind === 'today' ? null : ($after->equalTo($today) ? $url(['period' => 'today']) : $url(['day' => $after->toDateString()]));
        }

        $lastMonth = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $picks = [
            'yesterday' => ['day' => $today->copy()->subDay()->toDateString()],
            'week' => ['from' => $today->copy()->startOfWeek(Carbon::SATURDAY)->toDateString(), 'to' => $today->toDateString()],
            'month' => ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
            'last_month' => ['from' => $lastMonth->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
        ];
        $current = SalesDashboard::period(['from' => $w['from'], 'to' => $w['to']], now());

        return [
            'label' => $label,
            'month' => substr($w['to'], 0, 7),
            'prev' => $prev,
            'next' => $next,
            'picks' => collect($picks)->map(fn (array $q, string $key) => [
                'label' => __('dashboard.calendar.picks.'.$key),
                'url' => $url($q),
                'on' => in_array($kind, ['day', 'range'], true) && SalesDashboard::period($q, now()) === $current,
            ])->values()->all(),
        ];
    }

    /**
     * La caisse des branches affichees dont l'agent l'envoie (1.0.8+), pour la periode : le solde a la fin de son
     * dernier jour (maintenant si c'est aujourd'hui), ce qui est entre et sorti pendant la periode, puis chaque branche.
     * Une branche d'agent plus ancien est nommee ("mettre l'agent a jour"), jamais comptee comme une caisse vide.
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function cash(Collection $branches, array $w): array
    {
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('cash'))->values();
        $figures = $ready->isEmpty() ? [] : CashBox::figures($ready->pluck('id')->all(), $w['from'], $w['to']);
        $today = now()->toDateString();
        $rows = $ready->map(function (Branch $b) use ($figures, $today) {
            $f = $figures[$b->id];
            $last = $f['last'] ? Carbon::parse($f['last']) : null;

            return ['branch' => $b->name, 'hue' => $b->hue(), 'balance' => $f['balance'], 'in' => $f['in'], 'out' => $f['out'],
                // aujourd'hui : l'heure seule ; un autre jour : le jour et l'heure
                'last' => $last ? $last->format($last->toDateString() === $today ? 'H:i' : 'd/m H:i') : null,
                'checked_at' => $f['checked_at'] ? Carbon::parse($f['checked_at']) : null];
        })->sortByDesc('balance')->values();
        $now = $w['to'] >= $today;

        return [
            'total' => round((float) $rows->sum('balance'), 2),
            'in' => round((float) $rows->sum('in'), 2),
            'out' => round((float) $rows->sum('out'), 2),
            // les titres : le solde maintenant ou a la fin d'un jour passe ; l'argent d'aujourd'hui, d'un jour ou de la periode
            'now' => $now,
            'end' => Carbon::parse($w['to'])->format('d/m/Y'),
            'flow' => $w['from'] !== $w['to'] ? 'period' : ($now ? 'today' : 'day'),
            'branches' => $rows,
            // la caisse la moins recemment confirmee par son agent
            'checked_at' => $rows->contains(fn (array $r) => $r['checked_at'] === null) ? null : $rows->min('checked_at'),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('cash'))->pluck('name')->values(),
        ];
    }

    /**
     * Une case de la courbe : son libelle court (sous la barre) et sa bulle (jour entier, montant, bons).
     *
     * @param  array{key: string, amount: float, bons: int}  $p
     * @return array{label: string, tip: string, value: float}
     */
    private function point(array $p, string $unit, bool $sale): array
    {
        $locale = app()->getLocale();
        [$label, $when] = match ($unit) {
            'hour' => [sprintf('%02d', (int) $p['key']), sprintf('%02d:00', (int) $p['key'])],
            'month' => [Carbon::parse($p['key'].'-01')->locale($locale)->translatedFormat('M'), Carbon::parse($p['key'].'-01')->locale($locale)->translatedFormat('F Y')],
            default => [Carbon::parse($p['key'])->format('d/m'), Carbon::parse($p['key'])->locale($locale)->translatedFormat('l d/m')],
        };
        $bons = trans_choice('dashboard.bons_count', $p['bons'], ['count' => $p['bons']]);

        return [
            'label' => $label,
            // le montant isole de gauche a droite : dans une phrase arabe, "1 565 359" s'afficherait "359 565 1"
            'tip' => $when.' — '.($sale ? "\u{2066}".number_format($p['amount'], 0, ',', ' ')."\u{2069} (".$bons.')' : $bons),
            'value' => $sale ? $p['amount'] : $p['bons'],
        ];
    }
}
