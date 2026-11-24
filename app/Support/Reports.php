<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ProductGroupCode;
use App\Models\Scopes\ExerciseScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "التقارير" de "لوحة القيادة" (ReportController) : ce que le tableau de bord montre en dix lignes, ici en entier,
 * une liste par rapport, qu'on trie, filtre, cherche, imprime et exporte. Chaque rapport = ses colonnes (columns())
 * et ses lignes (data(), gardees une minute) ; le controleur fait le reste, le meme pour tous.
 * Les lignes ne portent que ce que le compte peut voir ($c['sale'] : montants de vente, $c['buy'] : marge, achats et
 * valeur du stock, $c['stock_ids'] : les branches dont il voit le stock), donc un tri ou un export ne trahit rien.
 * Bons, lignes et versements passent par leurs modeles : l'exercice affiche (ExerciseScope). "Jamais vendu depuis"
 * et "dernier achat" lisent tous les exercices (table brute) : une cloture ne rend pas tout le stock dormant.
 */
final class Reports
{
    /** Les rapports, par menu de la barre du haut. */
    public const GROUPS = [
        'sales' => ['products', 'days', 'customers', 'sellers', 'branches'],
        'stock' => ['dormant', 'reorder', 'stock'],
        'purchases' => ['bought', 'suppliers'],
        'debts' => ['receivable', 'payable'],
    ];

    /** Ceux qui portent sur une periode ; les autres disent l'etat de maintenant. */
    public const PERIODIC = ['products', 'days', 'customers', 'sellers', 'branches', 'reorder', 'bought', 'suppliers'];

    /** ?period= : comme le tableau de bord, plus "all" (tout l'exercice affiche). */
    public const PERIODS = ['today', '7', '30', '12m', 'all'];

    /** ?days= du stock dormant : pas vendu depuis ce nombre de jours. */
    public const DORMANT_DAYS = [30, 60, 90, 180, 365];

    /** Ceux ou l'on cherche un article ou un nom ; les autres ont peu de lignes. */
    public const SEARCHABLE = ['products', 'customers', 'dormant', 'reorder', 'stock', 'bought', 'suppliers', 'receivable', 'payable'];

    /** ?f= de chaque rapport, le premier par defaut ; chaque ligne dit ceux qu'elle passe (flags). */
    public const FILTERS = [
        'products' => ['all', 'a', 'b', 'c', 'returned', 'loss'],
        'dormant' => ['all', 'never'],
        'reorder' => ['all', 'zero', 'negative', 'low'],
        'stock' => ['available', 'negative', 'all'],
        'bought' => ['all', 'returned'],
        'receivable' => ['all', 'owes', 'credit'],
        'payable' => ['all', 'owes', 'credit'],
    ];

    /** Classement ABC : les articles qui font ces parts cumulees des ventes sont A, puis B, le reste C. */
    private const ABC = ['a' => 80.0, 'b' => 95.0];

    /** Jamais vendu : range apres le plus ancien quand on trie par jours sans vente. */
    public const NEVER = 99999;

    private const CACHE_SECONDS = 60;

    /** Au-dela, les lignes ne vont pas dans le cache (une table "cache" en base : pas de valeur de plusieurs Mo). */
    private const CACHE_ROWS = 6000;

    /** Le report d'un exercice clos : un versement "Autre" que Gescomm ecrit a la cloture (App\Models\Exercise). */
    private const OPENING = '%Solde Initial%';

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function groupOf(string $report): string
    {
        foreach (self::GROUPS as $group => $reports) {
            if (in_array($report, $reports, true)) {
                return $group;
            }
        }

        return 'sales';
    }

    /** Les dettes ne sont que des montants : celles des clients avec prices.sale, celles des fournisseurs avec prices.buy. */
    public static function allowed(string $report, bool $sale, bool $buy): bool
    {
        return match ($report) {
            'receivable' => $sale,
            'payable' => $buy,
            default => in_array($report, self::all(), true),
        };
    }

    /**
     * Les menus de la barre du haut : chaque groupe et le premier de ses rapports que le compte peut ouvrir.
     *
     * @return array<string, string>
     */
    public static function menu(bool $sale, bool $buy): array
    {
        $menu = [];
        foreach (self::GROUPS as $group => $reports) {
            foreach ($reports as $report) {
                if (self::allowed($report, $sale, $buy)) {
                    $menu[$group] = $report;
                    break;
                }
            }
        }

        return $menu;
    }

    /**
     * Les filtres (?f=) d'un rapport pour ce compte, le premier par defaut : "vendus a perte" demande la marge.
     *
     * @return list<string>
     */
    public static function filters(string $report, bool $sale, bool $buy): array
    {
        return array_values(array_filter(self::FILTERS[$report] ?? [], fn (string $f) => $f !== 'loss' || ($sale && $buy)));
    }

    /**
     * La periode d'un rapport, lue comme celle du tableau de bord (?period=, ?day=, ?from=&to=), plus "all" : du premier
     * bon de ces branches (dans l'exercice affiche) a aujourd'hui. query = ce que les liens de la page gardent.
     *
     * @param  array<string, mixed>  $query
     * @param  list<int>  $ids
     * @return array{key: string, from: string, to: string, query: array<string, ?string>}
     */
    public static function window(array $query, Carbon $now, array $ids): array
    {
        $today = $now->toDateString();
        $chosen = isset($query['day']) || isset($query['from']) || isset($query['to']);
        if (! $chosen && ($query['period'] ?? null) === 'all') {
            $first = $ids === [] ? null : Document::query()->whereIn('branch_id', $ids)->min('date');

            return ['key' => 'all', 'from' => $first === null ? $today : min(substr((string) $first, 0, 10), $today), 'to' => $today, 'query' => ['period' => 'all']];
        }
        $period = SalesDashboard::period($query, $now);
        $w = SalesDashboard::window($period, $now);
        $kind = SalesDashboard::kind($period);

        return [
            'key' => in_array($kind, ['day', 'range'], true) ? 'custom' : $period,
            'from' => $w['from'],
            'to' => $w['to'],
            'query' => match ($kind) {
                'day' => ['day' => $w['from']],
                'range' => ['from' => $w['from'], 'to' => $w['to']],
                default => ['period' => $period === '30' ? null : $period],
            },
        ];
    }

    /**
     * Les colonnes d'un rapport pour ce compte : key (la valeur dans la ligne, et ?sort=), type (comment l'ecrire),
     * label (reports.cols), sum (totalisee en bas), sm (gardee sur telephone), dir (sens du premier tri).
     * Une colonne que le compte ne peut pas voir n'existe pas : ni affichee, ni triable, ni exportee.
     *
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     */
    public static function columns(string $report, array $c): array
    {
        $sale = $c['sale'];
        $buy = $c['buy'];
        $branch = $c['several'] ? [self::col('branch', 'branch', ['dir' => 'asc'])] : [];
        $item = self::col('item', 'item', ['sm' => true, 'dir' => 'asc']);
        $stock = self::col('stock', 'stock', ['sm' => true]);

        $columns = match ($report) {
            'products' => [
                $item,
                self::col('class', 'class', ['label' => 'class', 'dir' => 'asc']),
                self::col('out', 'qty', ['label' => 'out_sale', 'sum' => true]),
                self::col('back', 'qty', ['sum' => true]),
                self::col('net', 'qty', ['sum' => true, 'sm' => ! $sale]),
                self::col('bons', 'int'),
                $sale ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $sale && $buy ? self::col('margin', 'money', ['sum' => true]) : null,
                $sale && $buy ? self::col('margin_pct', 'pct') : null,
                $c['stock_ids'] !== [] ? $stock : null,
                self::col('last', 'date', ['label' => 'last_sale']),
            ],
            'bought' => [
                $item,
                self::col('out', 'qty', ['label' => 'out_purchase', 'sum' => true]),
                self::col('back', 'qty', ['sum' => true]),
                self::col('net', 'qty', ['sum' => true, 'sm' => ! $buy]),
                self::col('bons', 'int'),
                $buy ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $c['stock_ids'] !== [] ? $stock : null,
                self::col('last', 'date', ['label' => 'last_purchase']),
            ],
            'days' => [
                self::col('day', 'period', ['sm' => true, 'label' => $c['unit'] === 'month' ? 'month' : 'day']),
                self::col('bons', 'int', ['sum' => true, 'sm' => ! $sale]),
                $sale ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                $sale ? self::col('average', 'money') : null,
                $sale ? self::col('back', 'money', ['label' => 'back_amount', 'sum' => true]) : null,
                $sale && $buy ? self::col('margin', 'money', ['sum' => true]) : null,
                $sale && $buy ? self::col('margin_pct', 'pct') : null,
                self::col('p_bons', 'int', ['sum' => true]),
                $buy ? self::col('p_amount', 'money', ['sum' => true]) : null,
            ],
            'customers' => [
                self::col('partner', 'partner', ['sm' => true, 'label' => 'customer', 'dir' => 'asc']),
                ...$branch,
                self::col('bons', 'int', ['sum' => true, 'sm' => ! $sale]),
                $sale ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $sale ? self::col('average', 'money') : null,
                $sale ? self::col('back', 'money', ['label' => 'back_amount', 'sum' => true]) : null,
                $sale && $buy ? self::col('margin', 'money', ['sum' => true]) : null,
                $sale && $buy ? self::col('margin_pct', 'pct') : null,
                $sale ? self::col('paid', 'money', ['sum' => true]) : null,
                self::col('last', 'date', ['label' => 'last_bon']),
            ],
            'suppliers' => [
                self::col('partner', 'partner', ['sm' => true, 'label' => 'supplier', 'dir' => 'asc']),
                ...$branch,
                self::col('bons', 'int', ['sum' => true, 'sm' => ! $buy]),
                $buy ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $buy ? self::col('average', 'money') : null,
                $buy ? self::col('paid', 'money', ['sum' => true]) : null,
                self::col('last', 'date', ['label' => 'last_bon']),
            ],
            'sellers' => [
                self::col('name', 'text', ['sm' => true, 'label' => 'seller', 'dir' => 'asc']),
                ...$branch,
                self::col('bons', 'int', ['sum' => true, 'sm' => ! $sale]),
                $sale ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $sale ? self::col('average', 'money') : null,
                self::col('customers', 'int'),
                self::col('last', 'date', ['label' => 'last_bon']),
            ],
            'branches' => [
                self::col('branch', 'branch', ['sm' => true, 'dir' => 'asc']),
                self::col('bons', 'int', ['sum' => true, 'sm' => ! $sale]),
                $sale ? self::col('amount', 'money', ['sum' => true, 'sm' => true]) : null,
                self::col('share', 'share', ['sum' => true]),
                $sale ? self::col('average', 'money') : null,
                $sale ? self::col('back', 'money', ['label' => 'back_amount', 'sum' => true]) : null,
                $sale && $buy ? self::col('margin', 'money', ['sum' => true]) : null,
                $sale && $buy ? self::col('margin_pct', 'pct') : null,
                $buy ? self::col('p_amount', 'money', ['sum' => true]) : null,
                self::col('items', 'int', ['label' => 'items_in_stock', 'sum' => true]),
                $buy ? self::col('value', 'money', ['label' => 'stock_value', 'sum' => true]) : null,
            ],
            'dormant' => [
                $item,
                ...$branch,
                self::col('stock', 'qty', ['sm' => true, 'label' => 'stock']),
                self::col('idle', 'idle', ['sm' => ! $buy]),
                self::col('last_sale', 'date'),
                self::col('last_purchase', 'date'),
                $buy ? self::col('buy', 'money2', ['label' => 'price_buy']) : null,
                $buy ? self::col('value', 'money', ['sum' => true, 'sm' => true]) : null,
            ],
            'reorder' => [
                $item,
                ...$branch,
                self::col('sold', 'qty', ['sm' => true]),
                self::col('bons', 'int'),
                $stock,
                self::col('alert', 'qty'),
                self::col('last_sale', 'date'),
                self::col('last_purchase', 'date'),
            ],
            'stock' => [
                $item,
                ...$branch,
                $stock,
                $buy ? self::col('buy', 'money2', ['label' => 'price_buy']) : null,
                $buy ? self::col('value', 'money', ['sum' => true, 'sm' => true]) : null,
                $buy ? self::col('share', 'share', ['sum' => true]) : null,
                $sale ? self::col('retail', 'money2', ['label' => 'price_retail']) : null,
                $sale ? self::col('retail_value', 'money', ['sum' => true]) : null,
            ],
            'receivable', 'payable' => [
                self::col('partner', 'partner', ['sm' => true, 'label' => $report === 'receivable' ? 'customer' : 'supplier', 'dir' => 'asc']),
                ...$branch,
                self::col('opening', 'money2', ['sum' => true]),
                self::col('billed', 'money2', ['sum' => true, 'label' => $report === 'receivable' ? 'billed_sale' : 'billed_purchase']),
                self::col('paid', 'money2', ['sum' => true, 'label' => 'paid_all']),
                self::col('balance', 'money2', ['sum' => true, 'sm' => true, 'strong' => true]),
                self::col('last', 'date', ['label' => 'last_bon']),
                self::col('last_pay', 'date'),
            ],
            default => [],
        };

        return array_values(array_filter($columns));
    }

    /** Le tri d'un rapport quand la page n'en demande pas. */
    public static function defaultSort(string $report, array $c): array
    {
        $has = fn (string $key) => in_array($key, array_column(self::columns($report, $c), 'key'), true);

        return match ($report) {
            'products', 'bought' => [$has('amount') ? 'amount' : 'net', 'desc'],
            'days' => ['day', 'desc'],
            'customers', 'suppliers', 'sellers', 'branches' => [$has('amount') ? 'amount' : 'bons', 'desc'],
            'dormant', 'stock' => [$has('value') ? 'value' : 'stock', 'desc'],
            'reorder' => ['sold', 'desc'],
            default => ['balance', 'desc'],
        };
    }

    /**
     * Les lignes et les chiffres d'un rapport : gardes une minute, par branches, periode et droits du compte.
     *
     * @param  array<string, mixed>  $c  ids (branches dont l'agent envoie les ventes), stock_ids (celles-la dont le compte
     *                                   voit le stock), shelf_ids (toutes celles affichees dont il voit le stock), from, to,
     *                                   today, sale, buy, several, days, unit
     * @return array{rows: list<array<string, mixed>>, tiles: list<array<string, mixed>>}
     */
    public static function data(string $report, array $c): array
    {
        $key = 'report:'.$report.':'.md5(json_encode([$c['ids'], $c['stock_ids'], $c['shelf_ids'], $c['from'], $c['to'], $c['today'],
            $c['sale'], $c['buy'], $c['days'], $c['unit']])).ExerciseScope::cacheKey();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $data = match ($report) {
            'products' => self::items($c, 'sale'),
            'bought' => self::items($c, 'purchase'),
            'days' => self::days($c),
            'customers' => self::partners($c, 'customer'),
            'suppliers' => self::partners($c, 'supplier'),
            'sellers' => self::sellers($c),
            'branches' => self::branches($c),
            'dormant' => self::dormant($c),
            'reorder' => self::reorder($c),
            'stock' => self::stock($c),
            'receivable' => self::balances($c, 'customer'),
            'payable' => self::balances($c, 'supplier'),
        };
        if (count($data['rows']) <= self::CACHE_ROWS) {
            Cache::put($key, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Ce qu'une colonne vaut pour le tri et l'export : le texte d'un article ou d'un nom, sinon la valeur.
     *
     * @param  array<int, string>  $names  les noms des branches
     */
    public static function value(array $col, array $row, array $names): mixed
    {
        return match ($col['type']) {
            'item' => $row['designation'] ?? $row['code'],
            'partner' => $row['name'] ?? $row['code'],
            'branch' => $names[$row['branch_id']] ?? null,
            default => $row[$col['key']] ?? null,
        };
    }

    private static function col(string $key, string $type, array $more = []): array
    {
        return $more + ['key' => $key, 'type' => $type, 'label' => $key, 'sum' => false, 'sm' => false,
            'dir' => in_array($type, ['text', 'item', 'partner', 'branch'], true) ? 'asc' : 'desc', 'strong' => false];
    }

    private static function tile(string $key, string $type, mixed $value, ?array $note = null): array
    {
        return ['key' => $key, 'type' => $type, 'value' => $value, 'note' => $note];
    }

    private static function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private static function pct(float $part, float $whole): ?float
    {
        return abs($whole) < 0.005 ? null : round($part / $whole * 100, 1);
    }

    /**
     * Le premier code de chaque groupe de codes fusionnes ("دمج الأصناف") : ils comptent pour un seul article.
     *
     * @return array<string, string> code_key => le code sous lequel le groupe est compte
     */
    private static function leaders(): array
    {
        $leaders = [];
        foreach (ProductGroupCode::query()->orderBy('code_key')->get(['product_group_id', 'code_key'])->groupBy('product_group_id') as $codes) {
            foreach ($codes as $code) {
                $leaders[(string) $code->code_key] = (string) $codes[0]->code_key;
            }
        }

        return $leaders;
    }

    /**
     * Les articles vendus (ou achetes) dans la periode, tous : sortis, rendus, net, bons, montant, part, marge, stock
     * maintenant, derniere vente ; et leur classe ABC (A = ceux qui font 80 % des ventes).
     */
    private static function items(array $c, string $kind): array
    {
        $money = $kind === 'sale' ? $c['sale'] : $c['buy'];
        $margin = $kind === 'sale' && $c['sale'] && $c['buy'];
        $sold = DocumentLine::query()->where('kind', $kind)->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('code_key')->selectRaw('code_key,
                SUM(CASE WHEN qty > 0 THEN qty ELSE 0 END) as out_qty,
                SUM(CASE WHEN qty < 0 THEN -qty ELSE 0 END) as back_qty,
                SUM(qty) as net, SUM(total) as amount,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed,
                COUNT(DISTINCT document_id) as bons, MAX(date) as last_day')->toBase()->get();
        if ($sold->isEmpty()) {
            return ['rows' => [], 'tiles' => []];
        }

        $leaders = self::leaders();
        $items = [];
        foreach ($sold as $r) {
            $key = $leaders[(string) $r->code_key] ?? (string) $r->code_key;
            $items[$key] ??= ['key' => $key, 'code' => null, 'designation' => null, 'out' => 0.0, 'back' => 0.0, 'net' => 0.0, 'bons' => 0,
                'amount' => 0.0, 'margin' => 0.0, 'costed' => 0.0, 'last' => null, 'stock' => null];
            $item = &$items[$key];
            $item['out'] += (float) $r->out_qty;
            $item['back'] += (float) $r->back_qty;
            $item['net'] += (float) $r->net;
            $item['bons'] += (int) $r->bons;
            $item['amount'] += (float) $r->amount;
            $item['margin'] += (float) $r->margin;
            $item['costed'] += (float) $r->costed;
            $item['last'] = max($item['last'] ?? '', (string) self::day($r->last_day));
            unset($item);
        }

        // designation et stock de maintenant : les fiches de ces branches (le stock, seulement de celles que le compte voit)
        $sees = array_flip($c['stock_ids']);
        foreach (DB::table('branch_stock')->whereIn('branch_id', $c['ids'])->get(['branch_id', 'code_key', 'code_raw', 'designation', 'stock']) as $s) {
            $key = $leaders[(string) $s->code_key] ?? (string) $s->code_key;
            if (! isset($items[$key])) {
                continue;
            }
            if ($items[$key]['code'] === null || (string) $s->code_key === $key) {
                $items[$key]['code'] = trim((string) $s->code_raw);
                $items[$key]['designation'] = trim((string) $s->designation) ?: $items[$key]['designation'];
            }
            if (isset($sees[$s->branch_id])) {
                $items[$key]['stock'] = round(($items[$key]['stock'] ?? 0) + (float) $s->stock, 2);
            }
        }

        // la part de chacun et sa classe : par montant, ou par quantite nette sans les montants
        $metric = $money ? 'amount' : 'net';
        uasort($items, fn (array $a, array $b) => $b[$metric] <=> $a[$metric]);
        $total = array_sum(array_map(fn (array $i) => max(0, $i[$metric]), $items));
        $before = 0.0;
        $rows = [];
        $classes = ['a' => 0, 'b' => 0, 'c' => 0];
        foreach ($items as $i) {
            $share = $total > 0 ? max(0, $i[$metric]) / $total * 100 : 0.0;
            $class = $i[$metric] <= 0 ? 'c' : ($before < self::ABC['a'] ? 'a' : ($before < self::ABC['b'] ? 'b' : 'c'));
            $before += $share;
            $classes[$class]++;
            $loss = $margin && $i['margin'] < -0.005;
            $rows[] = [
                'key' => (string) $i['key'],
                'code' => $i['code'] ?? (string) $i['key'],
                'designation' => $i['designation'],
                'class' => $kind === 'sale' ? $class : null,
                'out' => round($i['out'], 2),
                'back' => round($i['back'], 2),
                'net' => round($i['net'], 2),
                'bons' => $i['bons'],
                'amount' => $money ? round($i['amount'], 2) : null,
                'share' => round($share, 2),
                'margin' => $margin ? round($i['margin'], 2) : null,
                'costed' => $margin ? round($i['costed'], 2) : null,
                'margin_pct' => $margin ? self::pct($i['margin'], $i['costed']) : null,
                'stock' => $i['stock'],
                'state' => StockState::of($i['stock']),
                'last' => $i['last'] ?: null,
                'flags' => array_values(array_filter([$kind === 'sale' ? $class : null, $i['back'] > 0.005 ? 'returned' : null, $loss ? 'loss' : null])),
            ];
        }

        $sum = fn (string $f) => round(array_sum(array_column($items, $f)), 2);

        return ['rows' => $rows, 'tiles' => array_values(array_filter([
            self::tile('items', 'int', count($rows)),
            $money ? self::tile($kind === 'sale' ? 'sales' : 'purchases', 'money', $sum('amount')) : self::tile('net', 'qty', $sum('net')),
            $margin ? self::tile('margin', 'money', $sum('margin'), ['key' => 'margin_pct', 'type' => 'pct', 'value' => self::pct($sum('margin'), $sum('costed'))]) : null,
            self::tile('back', 'qty', $sum('back')),
            $kind === 'sale' ? self::tile('class_a', 'int', $classes['a'], ['key' => $money ? 'class_a' : 'class_a_qty', 'type' => 'pct', 'value' => self::pct($classes['a'], count($rows))]) : null,
        ]))];
    }

    /** Chaque jour (ou chaque mois) de la periode ou il s'est vendu ou achete quelque chose. */
    private static function days(array $c): array
    {
        $length = $c['unit'] === 'month' ? 7 : 10;
        $docs = fn (string $kind) => Document::query()->where('kind', $kind)->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('date')->selectRaw('date as d, COUNT(*) as bons, SUM(amount) as amount')->toBase()->get();
        $lines = DocumentLine::query()->where('kind', 'sale')->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('date')->selectRaw('date as d,
                SUM(CASE WHEN qty < 0 THEN -total ELSE 0 END) as back,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed')->toBase()->get();

        $days = [];
        $add = function (iterable $rows, array $fields) use (&$days, $length) {
            foreach ($rows as $r) {
                $key = substr((string) $r->d, 0, $length);
                $days[$key] ??= ['bons' => 0, 'amount' => 0.0, 'back' => 0.0, 'margin' => 0.0, 'costed' => 0.0, 'p_bons' => 0, 'p_amount' => 0.0];
                foreach ($fields as $to => $from) {
                    $days[$key][$to] += (float) $r->{$from};
                }
            }
        };
        $add($docs('sale'), ['bons' => 'bons', 'amount' => 'amount']);
        $add($lines, ['back' => 'back', 'margin' => 'margin', 'costed' => 'costed']);
        $add($docs('purchase'), ['p_bons' => 'bons', 'p_amount' => 'amount']);
        krsort($days);

        $sale = $c['sale'];
        $margin = $sale && $c['buy'];
        $rows = [];
        foreach ($days as $key => $d) {
            $rows[] = [
                'day' => (string) $key,
                'bons' => (int) $d['bons'],
                'amount' => $sale ? round($d['amount'], 2) : null,
                'average' => $sale && $d['bons'] > 0 ? round($d['amount'] / $d['bons'], 2) : null,
                'back' => $sale ? round($d['back'], 2) : null,
                'margin' => $margin ? round($d['margin'], 2) : null,
                'costed' => $margin ? round($d['costed'], 2) : null,
                'margin_pct' => $margin ? self::pct($d['margin'], $d['costed']) : null,
                'p_bons' => (int) $d['p_bons'],
                'p_amount' => $c['buy'] ? round($d['p_amount'], 2) : null,
                'flags' => [],
            ];
        }
        $sold = array_filter($days, fn (array $d) => $d['bons'] > 0);
        $metric = $sale ? 'amount' : 'bons';
        $best = collect($sold)->sortByDesc($metric)->keys()->first();
        $total = array_sum(array_column($days, 'amount'));
        $bons = (int) array_sum(array_column($days, 'bons'));

        return ['rows' => $rows, 'tiles' => array_values(array_filter([
            $sale ? self::tile('sales', 'money', round($total, 2)) : null,
            self::tile('bons', 'int', $bons, $sale && $bons > 0 ? ['key' => 'average_bon', 'type' => 'money', 'value' => round($total / $bons, 2)] : null),
            $sold === [] ? null : self::tile($c['unit'] === 'month' ? 'per_month' : 'per_day', $sale ? 'money' : 'int',
                round(($sale ? $total : $bons) / count($sold), $sale ? 2 : 0), ['key' => $c['unit'] === 'month' ? 'months_sold' : 'days_sold', 'type' => 'int', 'value' => count($sold)]),
            $best === null ? null : self::tile($c['unit'] === 'month' ? 'best_month' : 'best_day', 'period', (string) $best,
                ['key' => $sale ? 'plain' : 'bons', 'type' => $sale ? 'money' : 'int', 'value' => round($sold[$best][$metric], 2)]),
        ]))];
    }

    /**
     * Les clients (ou les fournisseurs) qui ont un bon dans la periode : bons, montant, part, bon moyen, retours et
     * marge (clients), ce qu'ils ont verse dans la periode (sans le report d'un exercice clos), dernier bon.
     * Un code est propre a sa branche : C001 d'Oran et C001 de Setif sont deux clients.
     */
    private static function partners(array $c, string $kind): array
    {
        $docKind = $kind === 'customer' ? 'sale' : 'purchase';
        $money = $kind === 'customer' ? $c['sale'] : $c['buy'];
        $margin = $kind === 'customer' && $c['sale'] && $c['buy'];
        $docs = Document::query()->where('kind', $docKind)->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->whereNotNull('partner_code')->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id, partner_code, COUNT(*) as bons, SUM(amount) as amount, MAX(date) as last_day')->toBase()->get();
        if ($docs->isEmpty()) {
            return ['rows' => [], 'tiles' => []];
        }
        $at = fn (object $r) => $r->branch_id.'|'.$r->partner_code;

        $names = Partner::query()->where('kind', $kind)->whereIn('branch_id', $c['ids'])->toBase()->get(['id', 'branch_id', 'code', 'name'])
            ->keyBy(fn (object $p) => $p->branch_id.'|'.$p->code);
        $paid = ! $money ? collect() : Payment::query()->where('partner_kind', $kind)->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', self::OPENING))
            ->groupBy('branch_id', 'partner_code')->selectRaw('branch_id, partner_code, SUM(amount) as paid')->toBase()->get()->keyBy($at);
        // retours et marge : les lignes de ses bons (clients seulement)
        $lines = $kind !== 'customer' || ! $money ? collect() : DocumentLine::query()
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('document_lines.kind', 'sale')->whereIn('document_lines.branch_id', $c['ids'])->whereBetween('document_lines.date', [$c['from'], $c['to']])
            ->groupBy('document_lines.branch_id', 'documents.partner_code')
            ->selectRaw('document_lines.branch_id as branch_id, documents.partner_code as partner_code,
                SUM(CASE WHEN document_lines.qty < 0 THEN -document_lines.total ELSE 0 END) as back,
                SUM(CASE WHEN document_lines.cost IS NOT NULL THEN document_lines.total - document_lines.qty * document_lines.cost ELSE 0 END) as margin,
                SUM(CASE WHEN document_lines.cost IS NOT NULL THEN document_lines.total ELSE 0 END) as costed')->toBase()->get()->keyBy($at);

        $metric = $money ? 'amount' : 'bons';
        $total = (float) $docs->sum(fn (object $r) => max(0, (float) $r->{$metric}));
        $rows = [];
        foreach ($docs as $r) {
            $key = $at($r);
            $l = $lines[$key] ?? null;
            $rows[] = [
                'id' => isset($names[$key]) ? (int) $names[$key]->id : null,
                'name' => isset($names[$key]) ? (trim((string) $names[$key]->name) ?: null) : null,
                'code' => (string) $r->partner_code,
                'branch_id' => (int) $r->branch_id,
                'bons' => (int) $r->bons,
                'amount' => $money ? round((float) $r->amount, 2) : null,
                'share' => $total > 0 ? round(max(0, (float) $r->{$metric}) / $total * 100, 2) : 0.0,
                'average' => $money && $r->bons > 0 ? round((float) $r->amount / $r->bons, 2) : null,
                'back' => $kind === 'customer' && $money ? round((float) ($l->back ?? 0), 2) : null,
                'margin' => $margin ? round((float) ($l->margin ?? 0), 2) : null,
                'costed' => $margin ? round((float) ($l->costed ?? 0), 2) : null,
                'margin_pct' => $margin ? self::pct((float) ($l->margin ?? 0), (float) ($l->costed ?? 0)) : null,
                'paid' => $money ? round((float) ($paid[$key]->paid ?? 0), 2) : null,
                'last' => self::day($r->last_day),
                'flags' => [],
            ];
        }
        $amount = round((float) $docs->sum('amount'), 2);
        $bons = (int) $docs->sum('bons');

        return ['rows' => $rows, 'tiles' => array_values(array_filter([
            self::tile($kind === 'customer' ? 'customers' : 'suppliers', 'int', count($rows)),
            $money ? self::tile($kind === 'customer' ? 'sales' : 'purchases', 'money', $amount) : null,
            self::tile('bons', 'int', $bons, $money && $bons > 0 ? ['key' => 'average_bon', 'type' => 'money', 'value' => round($amount / $bons, 2)] : null),
            $money ? self::tile('paid', 'money', round((float) $paid->sum('paid'), 2)) : null,
        ]))];
    }

    /** Qui a saisi les bons de vente de la periode (AGENT de Gescomm), par branche. */
    private static function sellers(array $c): array
    {
        $docs = Document::query()->where('kind', 'sale')->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('branch_id', 'entered_by')
            ->selectRaw('branch_id, entered_by, COUNT(*) as bons, SUM(amount) as amount, COUNT(DISTINCT partner_code) as customers, MAX(date) as last_day')->toBase()->get();
        $metric = $c['sale'] ? 'amount' : 'bons';
        $total = (float) $docs->sum(fn (object $r) => max(0, (float) $r->{$metric}));
        $rows = $docs->map(fn (object $r) => [
            'name' => trim((string) $r->entered_by) ?: null,
            'branch_id' => (int) $r->branch_id,
            'bons' => (int) $r->bons,
            'amount' => $c['sale'] ? round((float) $r->amount, 2) : null,
            'share' => $total > 0 ? round(max(0, (float) $r->{$metric}) / $total * 100, 2) : 0.0,
            'average' => $c['sale'] && $r->bons > 0 ? round((float) $r->amount / $r->bons, 2) : null,
            'customers' => (int) $r->customers,
            'last' => self::day($r->last_day),
            'flags' => [],
        ])->all();

        return ['rows' => $rows, 'tiles' => $rows === [] ? [] : array_values(array_filter([
            self::tile('sellers', 'int', count($rows)),
            $c['sale'] ? self::tile('sales', 'money', round((float) $docs->sum('amount'), 2)) : null,
            self::tile('bons', 'int', (int) $docs->sum('bons')),
        ]))];
    }

    /** Chaque branche sur la periode : ventes, part, bon moyen, retours, marge, achats, et son stock maintenant. */
    private static function branches(array $c): array
    {
        $by = fn ($query) => $query->toBase()->get()->keyBy('branch_id');
        $docs = fn (string $kind) => $by(Document::query()->where('kind', $kind)->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) as bons, SUM(amount) as amount'));
        $sales = $docs('sale');
        $purchases = $docs('purchase');
        $lines = $by(DocumentLine::query()->where('kind', 'sale')->whereIn('branch_id', $c['ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('branch_id')->selectRaw('branch_id,
                SUM(CASE WHEN qty < 0 THEN -total ELSE 0 END) as back,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed'));
        $stock = DB::table('branch_stock')->whereIn('branch_id', $c['stock_ids'])->where('stock', '>', 0.005)->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as items, SUM(CASE WHEN price_buy IS NOT NULL THEN stock * price_buy ELSE 0 END) as value')->get()->keyBy('branch_id');

        $sale = $c['sale'];
        $margin = $sale && $c['buy'];
        $metric = $sale ? 'amount' : 'bons';
        $total = (float) $sales->sum(fn (object $r) => max(0, (float) $r->{$metric}));
        $rows = [];
        foreach ($c['ids'] as $id) {
            $s = $sales[$id] ?? null;
            $l = $lines[$id] ?? null;
            $bons = (int) ($s->bons ?? 0);
            $amount = (float) ($s->amount ?? 0);
            $rows[] = [
                'branch_id' => $id,
                'bons' => $bons,
                'amount' => $sale ? round($amount, 2) : null,
                'share' => $total > 0 ? round(max(0, $sale ? $amount : $bons) / $total * 100, 2) : 0.0,
                'average' => $sale && $bons > 0 ? round($amount / $bons, 2) : null,
                'back' => $sale ? round((float) ($l->back ?? 0), 2) : null,
                'margin' => $margin ? round((float) ($l->margin ?? 0), 2) : null,
                'costed' => $margin ? round((float) ($l->costed ?? 0), 2) : null,
                'margin_pct' => $margin ? self::pct((float) ($l->margin ?? 0), (float) ($l->costed ?? 0)) : null,
                'p_amount' => $c['buy'] ? round((float) ($purchases[$id]->amount ?? 0), 2) : null,
                // le stock d'une branche que le compte ne voit pas : rien, pas zero
                'items' => isset($stock[$id]) ? (int) $stock[$id]->items : (in_array($id, $c['stock_ids'], true) ? 0 : null),
                'value' => $c['buy'] && in_array($id, $c['stock_ids'], true) ? round((float) ($stock[$id]->value ?? 0), 2) : null,
                'flags' => [],
            ];
        }

        return ['rows' => $rows, 'tiles' => array_values(array_filter([
            self::tile('branches', 'int', count($rows)),
            $sale ? self::tile('sales', 'money', round((float) $sales->sum('amount'), 2)) : self::tile('bons', 'int', (int) $sales->sum('bons')),
            $margin ? self::tile('margin', 'money', round((float) $lines->sum('margin'), 2),
                ['key' => 'margin_pct', 'type' => 'pct', 'value' => self::pct((float) $lines->sum('margin'), (float) $lines->sum('costed'))]) : null,
            $c['buy'] ? self::tile('purchases', 'money', round((float) $purchases->sum('amount'), 2)) : null,
        ]))];
    }

    /** La date du dernier bon d'un article dans sa branche, tous exercices : une sous-requete de branch_stock. */
    private static function lastLine(string $kind)
    {
        return DB::table('document_lines')->selectRaw('MAX(document_lines.date)')
            ->whereColumn('document_lines.code_key', 'branch_stock.code_key')
            ->where('document_lines.kind', $kind)
            ->whereColumn('document_lines.branch_id', 'branch_stock.branch_id');
    }

    /**
     * En stock, et aucune vente dans la branche depuis $c['days'] jours : chacun avec son stock, sa derniere vente
     * (ou jamais), son dernier achat, et sa valeur au prix d'achat.
     */
    private static function dormant(array $c): array
    {
        $ids = $c['stock_ids'];
        $now = Carbon::parse($c['today']);
        $since = $now->copy()->subDays($c['days'] - 1)->toDateString();
        $inner = DB::table('branch_stock')->whereIn('branch_stock.branch_id', $ids)->where('branch_stock.stock', '>', 0.005)
            ->select(['branch_stock.branch_id', 'branch_stock.code_key', 'branch_stock.code_raw', 'branch_stock.designation', 'branch_stock.stock', 'branch_stock.price_buy'])
            ->selectSub(self::lastLine('sale'), 'last_sale')->selectSub(self::lastLine('purchase'), 'last_purchase');
        $found = DB::query()->fromSub($inner, 't')->where(fn ($q) => $q->whereNull('last_sale')->orWhere('last_sale', '<', $since))->get();

        $rows = [];
        foreach ($found as $r) {
            $last = self::day($r->last_sale);
            $rows[] = [
                'key' => (string) $r->code_key,
                'code' => trim((string) $r->code_raw),
                'designation' => trim((string) $r->designation) ?: null,
                'branch_id' => (int) $r->branch_id,
                'stock' => round((float) $r->stock, 2),
                'idle' => $last === null ? self::NEVER : (int) Carbon::parse($last)->diffInDays($now),
                'last_sale' => $last,
                'last_purchase' => self::day($r->last_purchase),
                'buy' => $c['buy'] && $r->price_buy !== null ? round((float) $r->price_buy, 2) : null,
                'value' => $c['buy'] && $r->price_buy !== null ? round((float) $r->stock * (float) $r->price_buy, 2) : null,
                'flags' => $last === null ? ['never'] : [],
            ];
        }
        $value = round((float) array_sum(array_column($rows, 'value')), 2);
        $all = ! $c['buy'] || $ids === [] ? 0.0 : (float) DB::table('branch_stock')->whereIn('branch_id', $ids)->where('stock', '>', 0.005)
            ->selectRaw('SUM(CASE WHEN price_buy IS NOT NULL THEN stock * price_buy ELSE 0 END) as value')->value('value');
        $never = count(array_filter($rows, fn (array $r) => $r['last_sale'] === null));

        return ['rows' => $rows, 'tiles' => $rows === [] ? [] : array_values(array_filter([
            self::tile('items', 'int', count($rows)),
            $c['buy'] ? self::tile('value', 'money', $value, ['key' => 'of_stock', 'type' => 'pct', 'value' => self::pct($value, $all)]) : null,
            self::tile('never', 'int', $never),
        ]))];
    }

    /**
     * Vendus dans la periode, et maintenant a 0, en negatif ou au seuil d'alerte dans la meme branche : ce qu'il faut
     * racheter. Les couleurs du stock sont celles de Gescomm (StockState).
     */
    private static function reorder(array $c): array
    {
        $sold = DocumentLine::query()->where('kind', 'sale')->whereIn('branch_id', $c['stock_ids'])->whereBetween('date', [$c['from'], $c['to']])
            ->groupBy('branch_id', 'code_key')->havingRaw('SUM(qty) > 0')
            ->selectRaw('branch_id, code_key, SUM(qty) as sold, COUNT(DISTINCT document_id) as bons, MAX(date) as last_sale');
        $found = DB::query()->fromSub($sold, 'sold')
            ->join('branch_stock', fn ($j) => $j->on('branch_stock.branch_id', '=', 'sold.branch_id')->on('branch_stock.code_key', '=', 'sold.code_key'))
            ->where(fn ($q) => $q->where('branch_stock.stock', '<', 0.005)
                ->orWhere(fn ($low) => $low->whereNotNull('branch_stock.stock_alert')->whereColumn('branch_stock.stock', '<=', 'branch_stock.stock_alert')))
            ->select(['sold.branch_id', 'sold.code_key', 'sold.sold', 'sold.bons', 'sold.last_sale', 'branch_stock.code_raw', 'branch_stock.designation',
                'branch_stock.stock', 'branch_stock.stock_alert'])
            ->selectSub(self::lastLine('purchase'), 'last_purchase')->get()
            // deux ecritures du meme code dans une branche (820ac, 820AC) : une seule ligne
            ->unique(fn (object $r) => $r->branch_id.'|'.$r->code_key);

        $rows = [];
        $count = ['zero' => 0, 'negative' => 0, 'low' => 0];
        foreach ($found as $r) {
            $stock = round((float) $r->stock, 2);
            $alert = $r->stock_alert === null ? null : round((float) $r->stock_alert, 2);
            $state = StockState::of($stock, $alert);
            if (isset($count[$state])) {
                $count[$state]++;
            }
            $rows[] = [
                'key' => (string) $r->code_key,
                'code' => trim((string) $r->code_raw),
                'designation' => trim((string) $r->designation) ?: null,
                'branch_id' => (int) $r->branch_id,
                'sold' => round((float) $r->sold, 2),
                'bons' => (int) $r->bons,
                'stock' => $stock,
                'state' => $state,
                'alert' => $alert,
                'last_sale' => self::day($r->last_sale),
                'last_purchase' => self::day($r->last_purchase),
                'flags' => $state === null ? [] : [$state],
            ];
        }

        return ['rows' => $rows, 'tiles' => $rows === [] ? [] : [
            self::tile('items', 'int', count($rows)),
            self::tile('zero', 'int', $count['zero']),
            self::tile('negative', 'int', $count['negative']),
            self::tile('low', 'int', $count['low']),
        ]];
    }

    /** Le stock de maintenant, article par article et branche par branche, et ce qu'il vaut (achat, detail). */
    private static function stock(array $c): array
    {
        $found = DB::table('branch_stock')->whereIn('branch_id', $c['shelf_ids'])
            ->where(fn ($q) => $q->where('stock', '>', 0.005)->orWhere('stock', '<', -0.005))
            ->get(['branch_id', 'code_key', 'code_raw', 'designation', 'stock', 'price', 'price_buy', 'stock_alert', 'stock_max']);
        $total = (float) $found->sum(fn (object $r) => $r->stock > 0 && $r->price_buy !== null ? (float) $r->stock * (float) $r->price_buy : 0);

        $rows = [];
        $value = $retail = 0.0;
        $negative = 0;
        foreach ($found as $r) {
            $stock = round((float) $r->stock, 2);
            $cost = $c['buy'] && $r->price_buy !== null ? round($stock * (float) $r->price_buy, 2) : null;
            $shop = $c['sale'] && $r->price !== null ? round($stock * (float) $r->price, 2) : null;
            if ($stock > 0) {
                $value += $cost ?? 0;
                $retail += $shop ?? 0;
            } else {
                $negative++;
            }
            $rows[] = [
                'key' => (string) $r->code_key,
                'code' => trim((string) $r->code_raw),
                'designation' => trim((string) $r->designation) ?: null,
                'branch_id' => (int) $r->branch_id,
                'stock' => $stock,
                'state' => StockState::of($stock, $r->stock_alert === null ? null : (float) $r->stock_alert, $r->stock_max === null ? null : (float) $r->stock_max),
                'buy' => $c['buy'] && $r->price_buy !== null ? round((float) $r->price_buy, 2) : null,
                'value' => $cost,
                'share' => $c['buy'] && $total > 0 && $stock > 0 ? round(($cost ?? 0) / $total * 100, 2) : 0.0,
                'retail' => $c['sale'] && $r->price !== null ? round((float) $r->price, 2) : null,
                'retail_value' => $shop,
                'flags' => [$stock > 0 ? 'available' : 'negative'],
            ];
        }

        return ['rows' => $rows, 'tiles' => $rows === [] ? [] : array_values(array_filter([
            self::tile('in_stock', 'int', count($rows) - $negative),
            $c['buy'] ? self::tile('value', 'money', round($value, 2)) : null,
            $c['sale'] ? self::tile('retail_value', 'money', round($retail, 2),
                $c['buy'] && $value > 0 ? ['key' => 'over_cost', 'type' => 'money', 'value' => round($retail - $value, 2)] : null) : null,
            self::tile('negative', 'int', $negative),
        ]))];
    }

    /**
     * Ce que chaque client doit (ou ce qu'on doit a chaque fournisseur), comme Gescomm l'affiche : le report de
     * l'exercice d'avant + ses bons - ses versements (PartnerController::balance). Seulement ceux dont le solde n'est pas nul.
     */
    private static function balances(array $c, string $kind): array
    {
        $at = fn (object $r) => $r->branch_id.'|'.$r->partner_code;
        $docs = Document::query()->where('kind', $kind === 'customer' ? 'sale' : 'purchase')->whereIn('branch_id', $c['ids'])->whereNotNull('partner_code')
            ->groupBy('branch_id', 'partner_code')->selectRaw('branch_id, partner_code, SUM(amount) as billed, MAX(date) as last_day')->toBase()->get()->keyBy($at);
        $pays = Payment::query()->where('partner_kind', $kind)->whereIn('branch_id', $c['ids'])->whereNotNull('partner_code')
            ->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id, partner_code,
                SUM(CASE WHEN note LIKE ? THEN -amount ELSE 0 END) as opening,
                SUM(CASE WHEN note LIKE ? THEN 0 ELSE amount END) as paid,
                MAX(CASE WHEN note LIKE ? THEN NULL ELSE date END) as last_pay', [self::OPENING, self::OPENING, self::OPENING])->toBase()->get()->keyBy($at);
        $names = Partner::query()->where('kind', $kind)->whereIn('branch_id', $c['ids'])->toBase()->get(['id', 'branch_id', 'code', 'name'])
            ->keyBy(fn (object $p) => $p->branch_id.'|'.$p->code);

        $rows = [];
        $owed = $credit = 0.0;
        foreach ($docs->keys()->merge($pays->keys())->unique() as $key) {
            $d = $docs[$key] ?? null;
            $p = $pays[$key] ?? null;
            $opening = round((float) ($p->opening ?? 0), 2);
            $billed = round((float) ($d->billed ?? 0), 2);
            $paid = round((float) ($p->paid ?? 0), 2);
            $balance = round($opening + $billed - $paid, 2);
            if (abs($balance) < 0.005) {
                continue;
            }
            $balance > 0 ? $owed += $balance : $credit -= $balance;
            [$branchId, $code] = explode('|', (string) $key, 2);
            $rows[] = [
                'id' => isset($names[$key]) ? (int) $names[$key]->id : null,
                'name' => isset($names[$key]) ? (trim((string) $names[$key]->name) ?: null) : null,
                'code' => $code,
                'branch_id' => (int) $branchId,
                'opening' => $opening,
                'billed' => $billed,
                'paid' => $paid,
                'balance' => $balance,
                'last' => self::day($d->last_day ?? null),
                'last_pay' => self::day($p->last_pay ?? null),
                'flags' => [$balance > 0 ? 'owes' : 'credit'],
            ];
        }
        $debtors = count(array_filter($rows, fn (array $r) => $r['balance'] > 0));

        return ['rows' => $rows, 'tiles' => $rows === [] ? [] : [
            self::tile($kind === 'customer' ? 'owed_to_us' : 'we_owe', 'money2', round($owed, 2)),
            self::tile($kind === 'customer' ? 'debtors' : 'creditors', 'int', $debtors),
            self::tile($kind === 'customer' ? 'their_credit' : 'our_credit', 'money2', round($credit, 2)),
            self::tile('net_balance', 'money2', round($owed - $credit, 2)),
        ]];
    }
}
