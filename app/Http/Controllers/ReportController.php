<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Scopes\ExerciseScope;
use App\Support\BranchScope;
use App\Support\Highlight;
use App\Support\PrintPeriod;
use App\Support\Reports;
use App\Support\SalesDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "التقارير" de "لوحة القيادة" : chaque rapport de App\Support\Reports en entier (articles vendus, stock dormant, a
 * racheter, valeur du stock, clients, fournisseurs, dettes, jour par jour, branches, vendeurs), qu'on trie par ses
 * colonnes, filtre, cherche pendant la frappe, imprime (print/report) et exporte (CSV pour Excel).
 * Memes branches que le tableau de bord (BranchScope::salesIds) et memes droits : sans prices.sale ni montants de vente
 * ni dettes des clients, sans prices.buy ni marge, ni achats, ni valeur du stock ; le stock, seulement des branches dont
 * le compte voit le stock. Une colonne qu'il ne voit pas n'est ni triable ni exportee.
 */
class ReportController extends Controller
{
    private const PER_PAGE = 50;

    /** Une impression : toute la liste, jusqu'a cette limite (annoncee sur la page au-dela). */
    private const PRINT_LIMIT = 5000;

    /** La courbe du rapport jour par jour : au-dela, les barres ne se lisent plus. */
    private const CHART_BARS = 124;

    public function show(Request $request, ?string $report = null): View|RedirectResponse
    {
        if ($report === null) {
            return redirect()->route('dashboard.reports', ['report' => Reports::all()[0]] + $request->query());
        }
        $page = $this->page($request, $report);
        $rows = $page['rows'];
        $pages = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $current = min(max(1, (int) $request->query('page', 1)), $pages);
        $metric = collect($page['columns'])->firstWhere('key', 'amount') ? 'amount' : 'bons';

        // ?partial=1 : seulement le bloc sous la recherche (filtres, liste, pages), pour la recherche pendant la frappe
        return view($request->boolean('partial') ? 'reports._list' : 'reports.index', [
            'matched' => $rows->count(),
            // la page demandee seulement (les totaux, eux, sont ceux de toute la liste)
            'rows' => $rows->slice(($current - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'offset' => ($current - 1) * self::PER_PAGE,
            'current' => $current,
            'pages' => $pages,
            // jour par jour : la courbe, du plus ancien au plus recent
            'chart' => $report !== 'days' || $rows->count() < 2 || $rows->count() > self::CHART_BARS ? null : [
                'metric' => $metric,
                'max' => max(1, (float) $rows->max($metric)),
                'points' => $rows->sortBy('day')->values(),
            ],
        ] + $page);
    }

    /** Le rapport sur papier : toute la liste (pas une page), dans le tri et le filtre de l'ecran. */
    public function print(Request $request, string $report): View
    {
        $page = $this->page($request, $report);

        return view('print.report', [
            'matched' => $page['rows']->count(),
            'rows' => $page['rows']->take(self::PRINT_LIMIT)->values(),
            'letter' => $page['scope']->count() === 1 ? $page['scope']->first() : $page['scope']->firstWhere('is_main', true),
        ] + $page);
    }

    /**
     * Le rapport en CSV pour Excel : toute la liste, dans le tri et le filtre de l'ecran. Point-virgule et virgule
     * decimale (Excel en francais), BOM pour l'arabe ; un texte qui commence comme une formule est neutralise.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        $page = $this->page($request, $report);
        $names = $page['names'];
        $number = fn ($v, int $decimals = 2) => $v === null ? '' : number_format((float) $v, $decimals, ',', '');
        $text = fn (?string $v) => $v !== null && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : (string) $v;
        $day = fn (?string $v) => $v === null ? '' : (strlen($v) === 10 ? Carbon::parse($v)->format('d/m/Y') : $v);

        // ou trouver l'article ailleurs : "Setif: 40 | Blida: 12,5"
        $where = fn (array $row) => collect($row['else'] ?? [])
            ->map(fn (array $e) => ($page['others']->get($e['branch_id'])?->name ?? '').': '.$number($e['stock'], abs($e['stock'] - round($e['stock'])) < 0.005 ? 0 : 2))->join(' | ');

        $head = [];
        foreach ($page['columns'] as $col) {
            match ($col['type']) {
                'item' => array_push($head, __('reports.cols.code'), __('reports.cols.designation')),
                'partner' => array_push($head, __('reports.cols.code'), __('reports.cols.'.$col['label'])),
                'elsewhere' => array_push($head, __('reports.cols.elsewhere_qty'), __('reports.cols.elsewhere')),
                default => $head[] = __('reports.cols.'.$col['label']),
            };
        }
        $cells = function (array $row) use ($page, $names, $number, $text, $day, $where) {
            $out = [];
            foreach ($page['columns'] as $col) {
                $v = $row[$col['key']] ?? null;
                match ($col['type']) {
                    'item' => array_push($out, $text($row['code']), $text($row['designation'])),
                    'partner' => array_push($out, $text($row['code']), $text($row['name'])),
                    'elsewhere' => array_push($out, $number($v), $text($where($row))),
                    'branch' => $out[] = $text($names[$row['branch_id']] ?? ''),
                    'text' => $out[] = $text($v),
                    'period', 'date' => $out[] = $day($v),
                    'class' => $out[] = $v === null ? '' : strtoupper($v),
                    'int' => $out[] = $number($v, 0),
                    'idle' => $out[] = $v === Reports::NEVER ? __('reports.never') : $number($v, 0),
                    default => $out[] = $number($v),
                };
            }

            return $out;
        };
        $totals = [];
        foreach ($page['columns'] as $i => $col) {
            $total = $page['totals'][$col['key']] ?? null;
            $first = $i === 0 ? [__('reports.total')] : [''];
            match (true) {
                in_array($col['type'], ['item', 'partner'], true) => array_push($totals, ...$first, ...['']),
                $col['type'] === 'elsewhere' => array_push($totals, '', ''),
                $i === 0 => array_push($totals, ...$first),
                default => $totals[] = $total === null ? '' : $number($total, $col['type'] === 'int' ? 0 : 2),
            };
        }

        return response()->streamDownload(function () use ($page, $head, $cells, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head, ';', '"', '');
            foreach ($page['rows'] as $row) {
                fputcsv($out, $cells($row), ';', '"', '');
            }
            if ($page['rows']->isNotEmpty()) {
                fputcsv($out, $totals, ';', '"', '');
            }
            fclose($out);
        }, 'rapport-'.$report.'-'.ExerciseScope::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Tout ce qu'un rapport montre a ce compte : ses colonnes, toutes ses lignes une fois cherchees, filtrees et
     * triees, leurs totaux, ses chiffres ; et ce que les liens de la page gardent dans l'adresse (state).
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, string $report): array
    {
        $user = $request->user();
        $sale = $user->can('prices.sale');
        $buy = $user->can('prices.buy');
        abort_unless(Reports::allowed($report, $sale, $buy), 403);

        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        // un agent d'avant 1.0.7 n'envoie pas les ventes : sa branche est nommee, jamais comptee comme "zero vente"
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $stockIds = $user->stockBranchIds();
        $sees = fn (Branch $b) => $stockIds === null || in_array($b->id, $stockIds, true);
        // les branches dont ce rapport peut montrer des lignes : le stock, seulement celles dont le compte voit le stock
        $scope = match ($report) {
            'stock' => $branches->filter($sees)->values(),
            'dormant', 'reorder' => $ready->filter($sees)->values(),
            default => $ready,
        };

        $now = ExerciseScope::now();
        $periodic = in_array($report, Reports::PERIODIC, true);
        $window = Reports::window($periodic ? $request->query() : [], $now, $ready->pluck('id')->all());
        $days = (int) $request->query('days');
        $days = in_array($days, Reports::DORMANT_DAYS, true) ? $days : SalesDashboard::DORMANT_DAYS;
        $span = (int) Carbon::parse($window['from'])->diffInDays(Carbon::parse($window['to'])) + 1;
        $askedUnit = in_array($request->query('unit'), ['day', 'month'], true) ? $request->query('unit') : null;
        $unit = $askedUnit ?? ($span <= 62 ? 'day' : 'month');

        // "النواقص" dit ou trouver l'article ailleurs : dans toutes les branches dont le compte voit le stock, meme quand
        // une seule est choisie en haut (c'est justement les autres qu'on cherche) ; ce qu'elles en vendent, seulement
        // de celles dont il voit aussi les ventes
        $others = $report === 'reorder' ? BranchScope::choices($user) : collect();
        $salesIds = $user->salesBranchIds();

        $c = [
            'ids' => $ready->pluck('id')->all(),
            'stock_ids' => $ready->filter($sees)->pluck('id')->values()->all(),
            'shelf_ids' => $branches->filter($sees)->pluck('id')->values()->all(),
            'else_ids' => $others->pluck('id')->sort()->values()->all(),
            'else_sales' => $others->filter(fn (Branch $b) => $b->agentSupports('documents') && ($salesIds === null || in_array($b->id, $salesIds, true)))
                ->pluck('id')->sort()->values()->all(),
            'from' => $window['from'],
            'to' => $window['to'],
            'today' => $now->toDateString(),
            'sale' => $sale,
            'buy' => $buy,
            'several' => $scope->count() > 1,
            'days' => $days,
            'unit' => $unit,
        ];
        $columns = Reports::columns($report, $c);
        $byKey = collect($columns)->keyBy('key');
        $data = $scope->isEmpty() ? ['rows' => [], 'tiles' => []] : Reports::data($report, $c);
        $names = $branches->pluck('name', 'id')->all();
        $rows = collect($data['rows']);

        // la recherche : chaque mot dans le code, la designation ou le nom, dans n'importe quel ordre
        $q = in_array($report, Reports::SEARCHABLE, true) ? trim(mb_substr((string) $request->query('q', ''), 0, 60)) : '';
        $terms = array_map(fn (string $term) => mb_strtoupper($term), Highlight::terms($q));
        if ($terms !== []) {
            $rows = $rows->filter(function (array $r) use ($terms) {
                $hay = mb_strtoupper(($r['code'] ?? '').' '.($r['designation'] ?? $r['name'] ?? ''));
                foreach ($terms as $term) {
                    if (! str_contains($hay, $term)) {
                        return false;
                    }
                }

                return true;
            });
        }

        $elsewhere = Reports::seesElsewhere($report, $c);
        $filters = Reports::filters($report, $sale, $buy, $elsewhere);
        $filter = in_array($request->query('f'), $filters, true) ? $request->query('f') : ($filters[0] ?? 'all');
        $passes = fn (string $f) => fn (array $r) => $f === 'all' || in_array($f, $r['flags'], true);
        $counts = collect($filters)->mapWithKeys(fn (string $f) => [$f => $rows->filter($passes($f))->count()])->all();
        $rows = $rows->filter($passes($filter));

        // le tri : une colonne que le compte voit, sinon celui du rapport ; les lignes sans valeur toujours a la fin
        [$defaultSort, $defaultDir] = Reports::defaultSort($report, $c);
        // (un tri par une colonne qu'il ne voit pas est ignore avec son sens : l'ordre trahirait les montants)
        $asked = $byKey->has((string) $request->query('sort'));
        $sort = $asked ? (string) $request->query('sort') : $defaultSort;
        $dir = $asked && in_array($request->query('dir'), ['asc', 'desc'], true) ? $request->query('dir') : ($sort === $defaultSort ? $defaultDir : $byKey[$sort]['dir']);
        $col = $byKey[$sort];
        $rows = $rows->sort(function (array $a, array $b) use ($col, $dir, $names) {
            $x = Reports::value($col, $a, $names);
            $y = Reports::value($col, $b, $names);
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null);
            }
            $order = is_string($x) || is_string($y) ? strnatcasecmp((string) $x, (string) $y) : $x <=> $y;

            return $dir === 'asc' ? $order : -$order;
        })->values();

        $totals = [];
        foreach ($columns as $column) {
            if ($column['sum']) {
                $totals[$column['key']] = round((float) $rows->sum($column['key']), 2);
            }
        }
        if ($byKey->has('margin_pct')) {
            $costed = (float) $rows->sum('costed');
            $totals['margin_pct'] = abs($costed) < 0.005 ? null : round($totals['margin'] / $costed * 100, 1);
        }
        if ($byKey->has('average') && ($totals['bons'] ?? 0) > 0) {
            $totals['average'] = round($totals['amount'] / $totals['bons'], 2);
        }

        return [
            'report' => $report,
            'group' => Reports::groupOf($report),
            // les rapports du meme menu que le compte peut ouvrir ; "les branches" quand il y en a plusieurs
            'tabs' => array_values(array_filter(Reports::GROUPS[Reports::groupOf($report)],
                fn (string $r) => Reports::allowed($r, $sale, $buy) && ($r !== 'branches' || $ready->count() > 1 || $report === 'branches'))),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'tiles' => $data['tiles'],
            'names' => $names,
            // les branches ou "النواقص" cherche l'article (leur nom, leur couleur, leur derniere synchronisation)
            'elsewhere' => $elsewhere,
            'others' => $others->keyBy('id'),
            'scope' => $scope,
            'several' => $c['several'],
            'selected' => BranchScope::selected($request),
            'branches' => $branches->count(),
            'outdated' => $report === 'stock' ? collect() : $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            // le compte voit les ventes de ces branches, mais le stock d'aucune : le rapport n'a rien a dire
            'unseen' => $scope->isEmpty() && in_array($report, Reports::GROUPS['stock'], true) && ($report === 'stock' ? $branches : $ready)->isNotEmpty(),
            'periodic' => $periodic,
            'window' => $window,
            'windowLabel' => PrintPeriod::label(['from' => $window['from'], 'to' => $window['to']]),
            'today' => $now->toDateString(),
            'days' => $days,
            'unit' => $unit,
            'q' => $q,
            'terms' => Highlight::terms($q),
            'searchable' => in_array($report, Reports::SEARCHABLE, true),
            'filters' => $filters,
            'filter' => $filter,
            'counts' => $counts,
            'sort' => $sort,
            'dir' => $dir,
            'money' => $sale,
            'buy' => $buy,
            // ce que les liens gardent : la periode (ou les jours du stock dormant), puis filtre, tri et recherche
            'periodState' => array_filter(($periodic ? $window['query'] : []) + [
                'days' => $report === 'dormant' && $days !== SalesDashboard::DORMANT_DAYS ? (string) $days : null,
                'unit' => $report === 'days' ? $askedUnit : null,
            ]),
            'listState' => array_filter([
                'f' => $filter === ($filters[0] ?? 'all') ? null : $filter,
                'sort' => $sort === $defaultSort && $dir === $defaultDir ? null : $sort,
                'dir' => $sort === $defaultSort && $dir === $defaultDir ? null : $dir,
                'q' => $q === '' ? null : $q,
            ]),
        ];
    }
}
