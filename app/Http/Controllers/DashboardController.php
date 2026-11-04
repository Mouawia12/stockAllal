<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchScope;
use App\Support\SalesDashboard;
use App\Support\StockState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * "لوحة القيادة" : ou en sont les ventes et les articles, sur une periode, dans les branches dont le compte voit les
 * ventes (BranchScope::salesIds, comme les bons). Les chiffres viennent de SalesDashboard ; ici on retire ce que le
 * compte ne peut pas voir : montants de vente sans prices.sale (il voit alors des bons et des quantites), marge,
 * achats et valeur du stock sans prices.buy, stock d'une branche dont il ne voit pas le stock.
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
        $period = in_array($request->query('period'), SalesDashboard::PERIODS, true) ? $request->query('period') : '30';
        $rank = $sale && $request->query('rank') !== 'qty' ? 'amount' : 'qty';

        $page = [
            'period' => $period,
            'rank' => $rank,
            'money' => $sale,
            'buy' => $buy,
            'selected' => BranchScope::selected($request),
            'branches' => $branches->count(),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
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
