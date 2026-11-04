<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\BranchTask;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\ProductGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres de "لوحة القيادة" (DashboardController) pour des branches et une periode : ventes et tendance,
 * courbe, branches, articles les plus vendus, meilleurs clients, heures de pointe, articles vendus puis epuises,
 * stock dormant. Ce sont des chiffres bruts, les memes pour tous les comptes qui voient ces branches : le
 * controleur retire ce qu'un compte ne peut pas voir (prix, stock d'une branche). Gardes une minute : la page
 * est ouverte souvent, et les agents n'envoient rien de neuf plus vite que ca.
 * Un retour est une ligne negative d'un bon normal (CLAUDE.md, Sales data) : le montant d'un bon est deja net.
 */
final class SalesDashboard
{
    /** ?period= : aujourd'hui (compare a hier jusqu'a la meme heure), 7 ou 30 jours, 12 mois (par mois). */
    public const PERIODS = ['today', '7', '30', '12m'];

    public const TOP = 10;

    /** En stock et pas vendu depuis ce nombre de jours = dormant. */
    public const DORMANT_DAYS = 90;

    private const CACHE_SECONDS = 60;

    /** Lignes brutes gardees pour les listes filtrees ensuite par compte (stock des branches qu'il voit). */
    private const RAW_LIST = 40;

    /**
     * @param  list<int>  $ids  branches dont l'agent envoie les ventes (1.0.7+), parmi celles affichees
     * @return array<string, mixed>
     */
    public static function figures(array $ids, string $period): array
    {
        sort($ids);

        return Cache::remember('sales-dashboard:'.implode(',', $ids).':'.$period, self::CACHE_SECONDS, fn () => self::compute($ids, $period, now()));
    }

    /**
     * La fenetre d'une periode et celle d'avant, pour la tendance. Aujourd'hui : hier jusqu'a la meme heure.
     *
     * @return array{from: string, to: string, prev_from: string, prev_to: string, until: ?string, unit: string}
     */
    public static function window(string $period, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();

        return match ($period) {
            'today' => ['from' => $today->toDateString(), 'to' => $today->toDateString(),
                'prev_from' => $today->copy()->subDay()->toDateString(), 'prev_to' => $today->copy()->subDay()->toDateString(),
                'until' => $now->format('H:i:s'), 'unit' => 'hour'],
            '12m' => ['from' => $today->copy()->startOfMonth()->subMonths(11)->toDateString(), 'to' => $today->toDateString(),
                'prev_from' => $today->copy()->startOfMonth()->subMonths(23)->toDateString(), 'prev_to' => $today->copy()->subYear()->toDateString(),
                'until' => null, 'unit' => 'month'],
            default => ['from' => $today->copy()->subDays((int) $period - 1)->toDateString(), 'to' => $today->toDateString(),
                'prev_from' => $today->copy()->subDays(2 * (int) $period - 1)->toDateString(), 'prev_to' => $today->copy()->subDays((int) $period)->toDateString(),
                'until' => null, 'unit' => 'day'],
        };
    }

    /** @return array<string, mixed> */
    private static function compute(array $ids, string $period, Carbon $now): array
    {
        $w = self::window($period, $now);
        $docs = fn (string $kind, string $from, string $to) => Document::query()
            ->where('kind', $kind)->whereIn('branch_id', $ids)->whereBetween('date', [$from, $to]);
        $lines = fn (string $from, string $to) => DocumentLine::query()
            ->where('kind', 'sale')->whereIn('branch_id', $ids)->whereBetween('date', [$from, $to]);
        // hier jusqu'a la meme heure : les bons sans heure n'y comptent pas (on ne sait pas quand ils ont ete faits)
        $prevDocs = fn () => $docs('sale', $w['prev_from'], $w['prev_to'])
            ->when($w['until'], fn ($q) => $q->whereNotNull('time')->where('time', '<=', $w['until']));

        $byBranch = fn ($query) => $query->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as bons, SUM(amount) as amount')->get()->keyBy('branch_id');
        $current = $byBranch($docs('sale', $w['from'], $w['to']));
        $previous = $byBranch($prevDocs());

        $lineTotals = fn ($query) => $query->selectRaw('
                SUM(CASE WHEN qty < 0 THEN -qty ELSE 0 END) as back_qty,
                SUM(CASE WHEN qty < 0 THEN -total ELSE 0 END) as back_amount,
                SUM(CASE WHEN qty < 0 THEN 1 ELSE 0 END) as back_lines,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed_amount,
                SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as without_cost,
                COUNT(DISTINCT code_key) as products')->first();
        $sold = $lineTotals($lines($w['from'], $w['to']));
        $before = $lineTotals($lines($w['prev_from'], $w['prev_to'])
            ->when($w['until'], fn ($q) => $q->whereIn('document_id', $prevDocs()->select('id'))));
        $purchases = $docs('purchase', $w['from'], $w['to'])->selectRaw('COUNT(*) as bons, SUM(amount) as amount')->first();

        return [
            'window' => $w,
            'at' => $now->toIso8601String(),
            'totals' => [
                'amount' => round((float) $current->sum('amount'), 2),
                'bons' => (int) $current->sum('bons'),
                'prev_amount' => round((float) $previous->sum('amount'), 2),
                'prev_bons' => (int) $previous->sum('bons'),
                'back_qty' => round((float) $sold->back_qty, 2),
                'back_amount' => round((float) $sold->back_amount, 2),
                'back_lines' => (int) $sold->back_lines,
                'margin' => round((float) $sold->margin, 2),
                'costed_amount' => round((float) $sold->costed_amount, 2),
                'prev_margin' => round((float) $before->margin, 2),
                'without_cost' => (int) $sold->without_cost,
                'products' => (int) $sold->products,
                'purchase_amount' => round((float) $purchases->amount, 2),
                'purchase_bons' => (int) $purchases->bons,
            ],
            'branches' => collect($ids)->mapWithKeys(fn (int $id) => [$id => [
                'amount' => round((float) ($current[$id]->amount ?? 0), 2),
                'bons' => (int) ($current[$id]->bons ?? 0),
                'prev_amount' => round((float) ($previous[$id]->amount ?? 0), 2),
                'prev_bons' => (int) ($previous[$id]->bons ?? 0),
            ]])->all(),
            'series' => self::series($docs('sale', $w['from'], $w['to']), $w, $now),
            'hours' => $docs('sale', $w['from'], $w['to'])->whereNotNull('time')
                ->groupByRaw('SUBSTR(time, 1, 2)')->selectRaw('SUBSTR(time, 1, 2) as hour, COUNT(*) as bons')
                ->pluck('bons', 'hour')->mapWithKeys(fn ($n, $h) => [(int) $h => (int) $n])->sortKeys()->all(),
            'products' => self::products($lines($w['from'], $w['to']), $ids),
            'customers' => self::customers($docs('sale', $w['from'], $w['to'])),
            'sold_out' => self::soldOut($lines($w['from'], $w['to'])),
            'dormant' => self::dormant($ids, $now),
            'synced_at' => BranchTask::whereIn('branch_id', $ids)->where('task', 'sales')->max('last_ok_at'),
        ];
    }

    /**
     * Les ventes par heure (aujourd'hui), par jour ou par mois, chaque case de la fenetre presente (un jour sans
     * vente = 0, pas un trou dans la courbe).
     *
     * @return list<array{key: string, amount: float, bons: int}>
     */
    private static function series($query, array $w, Carbon $now): array
    {
        if ($w['unit'] === 'hour') {
            $rows = $query->whereNotNull('time')->groupByRaw('SUBSTR(time, 1, 2)')
                ->selectRaw('SUBSTR(time, 1, 2) as k, COUNT(*) as bons, SUM(amount) as amount')->get()->keyBy(fn ($r) => (int) $r->k);
            // de 7 h (ou de la premiere vente) jusqu'a l'heure actuelle
            $first = min(7, (int) ($rows->keys()->min() ?? 7));
            $keys = range($first, max($first, (int) $now->format('G'), (int) ($rows->keys()->max() ?? 0)));
        } else {
            $rows = $query->groupBy('date')->selectRaw('date as k, COUNT(*) as bons, SUM(amount) as amount')->get()
                ->groupBy(fn ($r) => substr((string) $r->k, 0, $w['unit'] === 'month' ? 7 : 10))
                ->map(fn ($g) => (object) ['bons' => $g->sum('bons'), 'amount' => $g->sum('amount')]);
            $keys = [];
            for ($d = Carbon::parse($w['from']); $d->lte(Carbon::parse($w['to'])); $w['unit'] === 'month' ? $d->addMonth() : $d->addDay()) {
                $keys[] = $w['unit'] === 'month' ? $d->format('Y-m') : $d->toDateString();
            }
        }

        return array_map(fn ($k) => ['key' => (string) $k, 'amount' => round((float) ($rows[$k]->amount ?? 0), 2), 'bons' => (int) ($rows[$k]->bons ?? 0)], $keys);
    }

    /**
     * Les articles les plus vendus, par montant et par quantite nette ; les codes fusionnes (ProductGroup) comptent
     * pour un seul article. Avec, pour chacun, sa designation et son stock par branche (le compte n'en voit que
     * les branches dont il voit le stock).
     *
     * @return array{by_amount: list<array>, by_qty: list<array>}
     */
    private static function products($query, array $ids): array
    {
        $rows = $query->groupBy('code_key')->selectRaw('code_key,
                SUM(qty) as net, SUM(total) as amount,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed_amount,
                SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as without_cost')->get();
        if ($rows->isEmpty()) {
            return ['by_amount' => [], 'by_qty' => []];
        }

        $groups = ProductGroup::expand($rows->pluck('code_key'));
        $items = [];
        foreach ($rows as $r) {
            $codes = $groups[$r->code_key];
            $key = $codes[0];
            $items[$key] ??= ['key' => $key, 'codes' => $codes, 'net' => 0.0, 'amount' => 0.0, 'margin' => 0.0, 'costed_amount' => 0.0, 'without_cost' => 0];
            foreach (['net', 'amount', 'margin', 'costed_amount'] as $f) {
                $items[$key][$f] += (float) $r->{$f};
            }
            $items[$key]['without_cost'] += (int) $r->without_cost;
        }
        $items = collect($items);
        $byAmount = $items->sortByDesc('amount')->take(self::TOP)->values();
        $byQty = $items->sortByDesc('net')->take(self::TOP)->values();

        $codes = $byAmount->merge($byQty)->pluck('codes')->flatten()->unique()->values();
        $stock = BranchStock::whereIn('branch_id', $ids)->whereIn('code_key', $codes)
            ->get(['branch_id', 'code_key', 'code_raw', 'designation', 'stock'])->groupBy('code_key');
        $describe = function (array $item) use ($stock) {
            $rows = collect($item['codes'])->flatMap(fn ($k) => $stock[$k] ?? []);

            return [
                'key' => $item['key'],
                'code' => trim((string) ($rows->firstWhere('code_key', $item['key'])?->code_raw ?? $rows->first()?->code_raw ?? $item['key'])),
                'designation' => $rows->pluck('designation')->map(fn ($d) => trim((string) $d))->filter()->first(),
                'net' => round($item['net'], 2),
                'amount' => round($item['amount'], 2),
                'margin' => round($item['margin'], 2),
                'costed_amount' => round($item['costed_amount'], 2),
                'without_cost' => $item['without_cost'],
                // le stock par branche : le controleur ne garde que les branches dont le compte voit le stock
                'stock' => $rows->groupBy('branch_id')->map(fn ($r) => round((float) $r->sum('stock'), 2))->all(),
            ];
        };

        return ['by_amount' => $byAmount->map($describe)->all(), 'by_qty' => $byQty->map($describe)->all()];
    }

    /**
     * Les meilleurs clients de la periode, par montant et par nombre de bons. Un code client est propre a sa
     * branche : C001 d'Oran et C001 de Setif sont deux clients.
     *
     * @return array{by_amount: list<array>, by_bons: list<array>}
     */
    private static function customers($query): array
    {
        $rows = $query->whereNotNull('partner_code')->groupBy('branch_id', 'partner_code')
            ->selectRaw('branch_id, partner_code, COUNT(*) as bons, SUM(amount) as amount')->get();
        $pick = fn (string $by) => $rows->sortByDesc($by)->take(8)->values();
        // concat, pas merge : merge d'une collection Eloquent garde une ligne par id, et ces lignes n'en ont pas
        $shown = $pick('amount')->concat($pick('bons'));
        $partners = Partner::where('kind', 'customer')->whereIn('branch_id', $shown->pluck('branch_id')->unique())
            ->whereIn('code', $shown->pluck('partner_code')->unique())->get(['id', 'branch_id', 'code', 'name'])
            ->keyBy(fn (Partner $p) => $p->branch_id.'|'.$p->code);
        $describe = fn ($r) => [
            'branch_id' => (int) $r->branch_id,
            'code' => $r->partner_code,
            'name' => $partners[$r->branch_id.'|'.$r->partner_code]->name ?? null,
            'id' => $partners[$r->branch_id.'|'.$r->partner_code]->id ?? null,
            'bons' => (int) $r->bons,
            'amount' => round((float) $r->amount, 2),
        ];

        return ['by_amount' => $pick('amount')->map($describe)->all(), 'by_bons' => $pick('bons')->map($describe)->all()];
    }

    /**
     * Vendus dans la periode, et a 0 ou en negatif maintenant dans la meme branche : ce qu'il faut racheter.
     *
     * @return list<array{branch_id: int, key: string, code: string, designation: ?string, sold: float, stock: float}>
     */
    private static function soldOut($query): array
    {
        $sold = $query->groupBy('branch_id', 'code_key')->havingRaw('SUM(qty) > 0')
            ->selectRaw('branch_id, code_key, SUM(qty) as sold');

        return DB::query()->fromSub($sold, 'sold')
            ->join('branch_stock', fn ($j) => $j->on('branch_stock.branch_id', '=', 'sold.branch_id')->on('branch_stock.code_key', '=', 'sold.code_key'))
            ->where('branch_stock.stock', '<', 0.005)
            ->orderByDesc('sold.sold')->limit(self::RAW_LIST)
            ->get(['sold.branch_id', 'sold.code_key', 'sold.sold', 'branch_stock.code_raw', 'branch_stock.designation', 'branch_stock.stock'])
            // deux ecritures du meme code dans une branche (820ac, 820AC) : une seule ligne
            ->unique(fn ($r) => $r->branch_id.'|'.$r->code_key)
            ->map(fn ($r) => [
                'branch_id' => (int) $r->branch_id,
                'key' => $r->code_key,
                'code' => trim((string) $r->code_raw),
                'designation' => $r->designation ? trim($r->designation) : null,
                'sold' => round((float) $r->sold, 2),
                'stock' => round((float) $r->stock, 2),
            ])->values()->all();
    }

    /**
     * En stock, et aucune vente dans la branche depuis DORMANT_DAYS jours : combien par branche, leur valeur au prix
     * d'achat (quand Gescomm le donne), et les plus lourds.
     *
     * @return array{per_branch: array<int, array{count: int, value: float}>, items: list<array>}
     */
    private static function dormant(array $ids, Carbon $now): array
    {
        $since = $now->copy()->subDays(self::DORMANT_DAYS - 1)->toDateString();
        $base = BranchStock::query()->whereIn('branch_stock.branch_id', $ids)->where('branch_stock.stock', '>', 0.005)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('document_lines')
                ->whereColumn('document_lines.code_key', 'branch_stock.code_key')
                ->where('document_lines.kind', 'sale')
                ->whereColumn('document_lines.branch_id', 'branch_stock.branch_id')
                ->where('document_lines.date', '>=', $since));

        $perBranch = (clone $base)->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as n, SUM(CASE WHEN price_buy IS NOT NULL THEN stock * price_buy ELSE 0 END) as value')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->branch_id => ['count' => (int) $r->n, 'value' => round((float) $r->value, 2)]])->all();
        $items = (clone $base)->orderByRaw('COALESCE(stock * price_buy, 0) DESC')->orderByDesc('stock')->limit(self::RAW_LIST)
            ->get(['branch_id', 'code_key', 'code_raw', 'designation', 'stock', 'price_buy'])
            ->map(fn (BranchStock $r) => [
                'branch_id' => $r->branch_id,
                'key' => $r->code_key,
                'code' => trim((string) $r->code_raw),
                'designation' => $r->designation ? trim($r->designation) : null,
                'stock' => round((float) $r->stock, 2),
                'value' => $r->price_buy === null ? null : round((float) $r->stock * (float) $r->price_buy, 2),
            ])->all();

        return ['per_branch' => $perBranch, 'items' => $items];
    }

    /** La tendance en %, null quand il n'y a rien a comparer. */
    public static function trend(float $now, float $before): ?float
    {
        return abs($before) < 0.005 ? null : round(($now - $before) / abs($before) * 100, 1);
    }

    /** @param  Collection<int, mixed>|array  $items */
    public static function onlyBranches(Collection|array $items, ?array $ids): array
    {
        return collect($items)->filter(fn (array $item) => $ids === null || in_array($item['branch_id'], $ids, true))->values()->all();
    }
}
