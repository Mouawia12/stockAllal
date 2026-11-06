<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ProductGroup;
use App\Models\RefreshJob;
use App\Support\BranchScope;
use App\Support\PrintPeriod;
use App\Support\ProductActivity;
use App\Support\StockState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockController extends Controller
{
    private const MAX_RESULTS = 100;

    /** Une impression : tout le catalogue affiche, jusqu'a cette limite (annoncee sur la page au-dela). */
    private const PRINT_LIMIT = 20000;

    /** Lignes de ventes et d'achats de la fiche de stock imprimee. */
    private const PRINT_ITEM_LINES = 1000;

    /** Au-dela, l'etat du stock imprime ne montre que le total : les colonnes ne tiendraient plus sur la feuille. */
    private const PRINT_BRANCH_COLUMNS = 8;

    /** Filtres de la recherche (?f=), sur le total de l'article dans les branches affichees ; couleurs : App\Support\StockState. */
    public const FILTERS = ['all', 'available', 'zero', 'negative'];

    /** Recherche : une ligne par code_key (ou par groupe de codes fusionnes), toutes les branches confondues. */
    public function index(Request $request): View
    {
        return $this->page($request, false);
    }

    /** Page "دمج الأصناف" (administrateur) : la meme recherche, avec des cases a cocher pour fusionner des codes. */
    public function merge(Request $request): View
    {
        return $this->page($request, true);
    }

    /** Detail d'un produit dans chaque branche affichee (la fenetre "apercu"), avec l'etat d'un batch de mise a jour. */
    public function product(Request $request): JsonResponse
    {
        return response()->json($this->productData($request, (string) $request->query('code', ''), (string) $request->query('batch', '')));
    }

    /**
     * Page d'un article : son etiquette de rayon, puis son stock dans chaque branche affichee (le "rayon"),
     * puis ses ventes et achats (ProductActivity, ?period=).
     * ?partial=1&batch=... : seulement le rayon, relu par la page pendant "تحديث الآن".
     */
    public function item(Request $request): View
    {
        $data = $this->productData($request, (string) $request->query('code', ''), (string) $request->query('batch', ''));
        if ($request->boolean('partial')) {
            return view('stock._shelf', $data);
        }
        $shown = Branch::where('active', true)->among(BranchScope::ids($request))->orderBy('name')->get();

        return view('stock.item', $data + [
            'scope' => BranchScope::selected($request),
            'activity' => $data['designation'] === null ? null
                : ProductActivity::for($request->user(), $data['codes'], $shown, (string) $request->query('period', 'all')),
        ]);
    }

    /**
     * L'etat du stock sur papier : tout ce que la recherche et le filtre trouvent (sans mot : tout le catalogue affiche),
     * le stock de chaque branche et les prix que le compte peut voir. ?mode=count : la fiche d'inventaire, sans prix, avec
     * une colonne vide pour ce qui est compte.
     */
    public function printList(Request $request): View
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $terms = $this->terms($q);
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $mode = $request->query('mode') === 'count' ? 'count' : 'list';
        $sort = $request->query('sort') === 'name' ? 'name' : 'code';
        $ids = BranchScope::ids($request);
        $main = $this->priceBranch($request, $ids);
        $visible = $mode === 'count' ? [] : $this->visiblePrices($request);
        $found = $this->search($q, $terms, $main, $visible, $ids, $filter, self::PRINT_LIMIT);
        $branches = Branch::where('active', true)->among($ids)->orderBy('name')->get();

        return view('print.stock', [
            'q' => $q,
            'filter' => $filter,
            'mode' => $mode,
            'sort' => $sort,
            'results' => $found['results']->sortBy(fn (object $r) => $sort === 'name' ? (string) $r->designation : $r->code, SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'matched' => $found['matched'],
            'mainBranch' => $main,
            'showSale' => $main !== null && in_array('price', $visible, true),
            'showBuy' => $main !== null && in_array('price_buy', $visible, true),
            'columns' => $mode === 'list' && $branches->count() > 1 && $branches->count() <= self::PRINT_BRANCH_COLUMNS ? $branches : collect(),
            'letter' => $branches->count() === 1 ? $branches->first() : $branches->firstWhere('is_main', true),
            'branchNames' => $branches->pluck('name'),
        ]);
    }

    /** La fiche de stock d'un article sur papier : son stock dans chaque branche affichee, puis ses ventes et achats. */
    public function printItem(Request $request): View
    {
        $data = $this->productData($request, (string) $request->query('code', ''), '');
        // un article que le compte ne voit dans aucune branche affichee n'existe pas pour lui
        abort_if($data['designation'] === null, 404);
        $shown = Branch::where('active', true)->among(BranchScope::ids($request))->orderBy('name')->get();
        $window = PrintPeriod::read($request, ProductActivity::PERIODS, 'all');

        return view('print.item', $data + [
            'window' => $window,
            'letter' => $shown->count() === 1 ? $shown->first() : $shown->firstWhere('is_main', true),
            'activity' => ProductActivity::for($request->user(), $data['codes'], $shown, 'all', self::PRINT_ITEM_LINES, $window),
        ]);
    }

    /**
     * Barre Ctrl+K : les articles qui correspondent (8 au plus) dans les branches affichees, comme la recherche.
     * Jamais de prix ici : la barre sert a ouvrir la page d'un article.
     */
    public function palette(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $terms = $this->terms($q);
        $items = $terms === [] ? collect() : $this->search($q, $terms, null, [], BranchScope::ids($request), 'all', 8)['results'];

        return response()->json([
            'products' => $items->map(fn (object $r) => [
                'code' => $r->code,
                'merged' => $r->merged,
                'designation' => $r->designation,
                'total' => $r->total,
                'state' => StockState::of($r->total),
                'url' => route('stock.item', ['code' => $r->code]),
            ])->values(),
        ]);
    }

    /**
     * Ce que la fenetre "apercu" et la page d'un article montrent : chaque branche affichee (BranchScope : la branche
     * choisie en haut, sinon toutes celles que le compte voit), ses lignes et les prix que le compte peut voir.
     *
     * @return array<string, mixed>
     */
    private function productData(Request $request, string $code, string $batch): array
    {
        $key = BranchStock::keyFor($code);
        $codes = ProductGroup::codesOf($key);
        $visible = $this->visiblePrices($request);

        // les branches que ce compte ne voit pas n'apparaissent pas du tout
        $ids = BranchScope::ids($request);
        $branches = Branch::where('active', true)->among($ids)->orderBy('name')->get();
        $rows = BranchStock::whereIn('code_key', $codes)->whereIn('branch_id', $branches->pluck('id'))->orderBy('code_key')->get()->groupBy('branch_id');
        $jobs = $batch !== '' ? RefreshJob::where('batch', $batch)->get() : collect();
        $jobsByBranch = $jobs->groupBy('branch_id');
        $ref = $this->priceBranch($request, $ids);
        $mainRow = $ref ? $this->mainRow($rows->get($ref->id, collect())) : null;

        $list = $branches->map(function (Branch $branch) use ($rows, $jobsByBranch, $visible, $ref) {
            return [
                'branch' => $branch->name,
                'hue' => $branch->hue(),
                'main' => $branch->is_main,
                // reference des prix : les autres branches dont un prix en differe sont signalees
                'ref' => $branch->id === $ref?->id,
                'online' => $branch->isOnline(),
                'last_seen' => $branch->last_seen_at?->diffForHumans(),
                'status' => $this->branchStatus($jobsByBranch->get($branch->id)),
                'rows' => $rows->get($branch->id, collect())->map(fn (BranchStock $row) => [
                    'code' => $row->code_raw,
                    'designation' => $row->designation,
                    'stock' => $row->stock,
                    // seuil d'alerte et stock max de cette branche (agent 1.0.7) : vert / orange comme dans Gescomm
                    'state' => StockState::of($row->stock, $row->stock_alert, $row->stock_max),
                    ...$this->prices($row, $visible),
                    // une synchro complete n'ecrit que les lignes changees : la valeur est confirmee a la derniere synchro
                    'updated' => collect([$row->updated_at, $branch->last_sync_at])->filter()->max()?->diffForHumans(),
                ])->values(),
            ];
        })->sortByDesc(fn (array $b) => $b['rows']->isEmpty() ? -INF : $b['rows']->max('stock'))->values();

        $all = $rows->flatten(1);
        $total = round($all->sum('stock'), 2);
        $done = $jobs->isNotEmpty()
            && $jobs->every(fn (RefreshJob $job) => ! in_array($job->displayStatus(), ['pending', 'taken'], true));

        return [
            'code' => $key,
            'codes' => $codes,
            // la designation de la branche qui a le plus de stock, comme dans la recherche
            'designation' => $all->sortByDesc('stock')->first()?->designation,
            'total' => $total,
            'total_state' => $all->isEmpty() ? null : StockState::of($total),
            'branches_with_stock' => $list->filter(fn (array $b) => $b['rows']->sum('stock') > 0)->count(),
            'branches_count' => $branches->count(),
            'price_branch' => $ref?->name,
            // prix de reference : la fenetre signale les branches dont un prix en differe
            'main_price' => in_array('price', $visible, true) ? $mainRow?->price : null,
            'main_prices' => $mainRow && $visible !== [] ? $this->prices($mainRow, $visible) : null,
            'show_sale' => in_array('price', $visible, true),
            'show_buy' => in_array('price_buy', $visible, true),
            'branches' => $list,
            'done' => $done,
        ];
    }

    /**
     * Bouton "mettre a jour" : une demande par branche active affichee (BranchScope), recuperee par chaque agent sous ~10 s.
     * Code fusionne : une demande par code du groupe, l'agent ne lit toujours qu'un code a la fois.
     */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30'], 'batch' => ['nullable', 'uuid']]);
        $codes = ProductGroup::codesOf(BranchStock::keyFor($data['code']));
        // batch choisi par la page : une requete rejouee apres une coupure retrouve ses demandes au lieu d'en recreer
        $batch = $data['batch'] ?? (string) Str::uuid();
        if (RefreshJob::where('batch', $batch)->exists()) {
            return response()->json(['batch' => $batch]);
        }

        foreach (Branch::where('active', true)->among(BranchScope::ids($request))->pluck('id') as $branchId) {
            foreach ($codes as $code) {
                RefreshJob::create([
                    'batch' => $batch,
                    'branch_id' => $branchId,
                    'code_key' => $code,
                    'status' => 'pending',
                    'requested_by' => $request->user()?->id,
                ]);
            }
        }

        return response()->json(['batch' => $batch]);
    }

    /**
     * Page de recherche (ou "دمج الأصناف"). Avec ?partial=1 : seulement le bloc des resultats, pour la recherche
     * pendant la frappe. Une URL a part (et pas un en-tete) : le cache du navigateur ne confond jamais les deux.
     */
    private function page(Request $request, bool $merge): View
    {
        $q = trim((string) $request->query('q', ''));
        $terms = $this->terms($q);
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $ids = BranchScope::ids($request);
        $main = $this->priceBranch($request, $ids);

        $visible = $this->visiblePrices($request);
        // sans mot ni filtre : pas de liste, seulement les compteurs des filtres (tout le catalogue affiche) ;
        // avec un filtre seul : ses articles (ex. tous les articles a stock negatif)
        $found = $this->search($q, $terms, $main, $visible, $ids, $filter, $terms === [] && $filter === 'all' ? 0 : self::MAX_RESULTS);

        return view($request->boolean('partial') ? 'stock._results' : 'stock.index', [
            'q' => $q,
            'filter' => $filter,
            'counts' => $found['counts'],
            'matched' => $found['matched'],
            'terms' => $terms,
            'merge' => $merge,
            'mainBranch' => $main,
            'showSale' => in_array('price', $visible, true),
            'showBuy' => in_array('price_buy', $visible, true),
            'results' => $found['results'],
            'branchesTotal' => Branch::where('active', true)->among($ids)->count(),
            'noMain' => Branch::main() === null,
        ]);
    }

    /**
     * Mots de la recherche, en majuscules : "boulon 20" trouve "BOULON 8X20". Chaque mot doit se trouver
     * dans le code ou la designation. 5 mots au plus.
     *
     * @return list<string>
     */
    private function terms(string $q): array
    {
        $words = preg_split('/\s+/u', mb_strtoupper(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, 5);
    }

    /**
     * Branche de reference des prix parmi celles affichees : la principale si elle en fait partie ; sinon la seule
     * branche affichee (selecteur du haut), ou la branche du compte. Sinon aucune : pas de prix dans la recherche.
     *
     * @param  list<int>|null  $ids  BranchScope::ids
     */
    private function priceBranch(Request $request, ?array $ids): ?Branch
    {
        $main = Branch::main();
        if ($main && ($ids === null || in_array($main->id, $ids, true))) {
            return $main;
        }
        $id = match (true) {
            $ids !== null && count($ids) === 1 => $ids[0],
            $ids !== null && in_array($request->user()->branch_id, $ids, true) => $request->user()->branch_id,
            default => null,
        };

        return $id ? Branch::where('active', true)->find($id) : null;
    }

    /** Ligne de reference des prix dans la branche de reference ; si le groupe y a plusieurs codes, la mieux stockee. */
    private function mainRow(Collection $mainRows): ?BranchStock
    {
        return $mainRows->sortByDesc('stock')->first();
    }

    /**
     * Prix que ce compte peut voir : detail + gros avec "prices.sale", achat avec "prices.buy".
     * Un prix non permis n'est meme pas envoye a la page.
     *
     * @return list<string>
     */
    private function visiblePrices(Request $request): array
    {
        $user = $request->user();
        $fields = [];
        if ($user?->can('prices.sale')) {
            array_push($fields, 'price', 'price_wholesale');
        }
        if ($user?->can('prices.buy')) {
            $fields[] = 'price_buy';
        }

        return $fields;
    }

    /**
     * Prix d'une ligne pour l'affichage, seulement ceux que le compte peut voir.
     *
     * @param  list<string>  $visible
     * @return array<string, ?float>
     */
    private function prices(BranchStock $row, array $visible): array
    {
        return collect($visible)->mapWithKeys(fn (string $field) => [$field => $row->{$field}])->all();
    }

    /** Une branche vend-elle a un autre prix (detail ou gros) que la principale ? Les prix inconnus ne comptent pas. */
    private function salePricesDiffer(BranchStock $row, BranchStock $mainRow): bool
    {
        foreach (['price', 'price_wholesale'] as $field) {
            if ($row->{$field} !== null && $mainRow->{$field} !== null && abs($row->{$field} - $mainRow->{$field}) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Etat d'une branche dans un batch. Pour un groupe, la branche a une demande par code :
     * elle attend tant qu'une demande attend, et une seule lecture reussie suffit pour "done".
     */
    private function branchStatus(?Collection $jobs): ?string
    {
        if ($jobs === null) {
            return null;
        }
        $statuses = $jobs->map(fn (RefreshJob $job) => $job->displayStatus());
        foreach (['pending', 'taken', 'done', 'error', 'timeout'] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return 'not_found';
    }

    /**
     * Recherche (ou parcours avec un filtre) : un article par code_key, ou par groupe de codes fusionnes.
     * Le filtre porte sur le total de l'article dans les branches affichees ; les compteurs des filtres aussi, sur
     * tout ce qui correspond a la recherche (pas seulement les 100 premiers).
     *
     * @param  list<string>  $terms  vide = tout le catalogue (seulement avec un filtre, voir page())
     * @param  list<string>  $visible  prix que le compte peut voir (visiblePrices)
     * @param  list<int>|null  $branchIds  branches affichees (BranchScope::ids), null = toutes
     * @return array{results: Collection<int, object>, counts: array<string, int>, matched: int}
     */
    private function search(string $q, array $terms, ?Branch $main, array $visible, ?array $branchIds, string $filter = 'all', int $limit = self::MAX_RESULTS): array
    {
        $totalsOf = function (?array $codes) use ($terms, $branchIds) {
            // ESCAPE '!' : meme syntaxe en MySQL et en SQLite (le backslash ne l'est pas)
            $query = BranchStock::query()
                ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
                ->where('branches.active', true)
                ->when($branchIds !== null, fn ($w) => $w->whereIn('branch_stock.branch_id', $branchIds))
                ->when($codes !== null, fn ($w) => $w->whereIn('branch_stock.code_key', $codes));
            if ($codes === null) {
                foreach ($terms as $term) {
                    $like = '%'.$this->escapeLike($term).'%';
                    $query->where(fn ($w) => $w
                        ->whereRaw("branch_stock.code_key LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("UPPER(branch_stock.designation) LIKE ? ESCAPE '!'", [$like]));
                }
            }

            return $query->groupBy('branch_stock.code_key')->selectRaw('branch_stock.code_key as k, SUM(branch_stock.stock) as total');
        };

        $matched = $totalsOf(null);
        if ($terms !== []) {
            $upper = mb_strtoupper(trim($q));
            // code exact, puis code qui commence par la recherche, puis code qui la contient, puis designation seule
            $matched->orderByRaw("CASE WHEN branch_stock.code_key = ? THEN 0 WHEN branch_stock.code_key LIKE ? ESCAPE '!' THEN 1 WHEN branch_stock.code_key LIKE ? ESCAPE '!' THEN 2 ELSE 3 END",
                [$upper, $this->escapeLike($upper).'%', '%'.$this->escapeLike($terms[0]).'%']);
        }
        $totals = $matched->orderBy('branch_stock.code_key')->pluck('total', 'k')->map(fn ($t) => (float) $t);

        // codes fusionnes : un article par groupe, affiche sous le premier de ses codes trouve ; son total = tout le groupe
        $expanded = ProductGroup::expand($totals->keys());
        $missing = collect($expanded)->flatten()->unique()->diff($totals->keys())->values();
        if ($missing->isNotEmpty()) {
            $totals = $totals->union($totalsOf($missing->all())->pluck('total', 'k')->map(fn ($t) => (float) $t));
        }
        $units = $totals->keys()->filter(fn ($key) => isset($expanded[$key]))
            ->map(fn ($key) => ['code' => (string) $key, 'codes' => $expanded[$key]])
            ->unique(fn (array $unit) => $unit['codes'][0])
            ->map(fn (array $unit) => $unit + ['total' => round(collect($unit['codes'])->sum(fn ($c) => $totals[$c] ?? 0), 2)])
            ->values();

        $state = fn (array $unit) => StockState::of($unit['total']);
        $counts = [
            'all' => $units->count(),
            'available' => $units->filter(fn ($u) => $u['total'] > 0.005)->count(),
            'zero' => $units->filter(fn ($u) => $state($u) === 'zero')->count(),
            'negative' => $units->filter(fn ($u) => $state($u) === 'negative')->count(),
        ];
        $kept = $units->filter(fn (array $u) => match ($filter) {
            'available' => $u['total'] > 0.005,
            'zero', 'negative' => $state($u) === $filter,
            default => true,
        })->values();

        $shown = $kept->take($limit);
        $rows = BranchStock::query()
            ->whereIn('code_key', $shown->pluck('codes')->flatten())
            ->whereHas('branch', fn ($b) => $b->where('active', true))
            ->when($branchIds !== null, fn ($w) => $w->whereIn('branch_id', $branchIds))
            ->get()
            ->groupBy('code_key');

        $sale = in_array('price', $visible, true);
        $buy = in_array('price_buy', $visible, true);

        $results = $shown->map(function (array $unit) use ($rows, $main, $sale, $buy) {
            $group = collect($unit['codes'])->flatMap(fn (string $code) => $rows->get($code, collect()));
            $mainRow = $main ? $this->mainRow($group->where('branch_id', $main->id)) : null;

            return (object) [
                'code' => $unit['code'],
                // les autres codes du groupe, vide si le code n'est pas fusionne
                'merged' => array_values(array_diff($unit['codes'], [$unit['code']])),
                // la designation de la branche qui a le plus de stock
                'designation' => $group->sortByDesc('stock')->first()?->designation,
                'total' => $unit['total'],
                'branches' => $group->pluck('branch_id')->unique()->count(),
                // le stock de chaque branche et l'unite : pour l'impression (la page et la barre Ctrl+K ne les montrent pas)
                'by_branch' => $group->groupBy('branch_id')->map(fn (Collection $list) => round((float) $list->sum('stock'), 2))->all(),
                'unit' => $group->first()?->unit,
                // prix de la branche de reference : detail (تجزئة) et gros (جملة) avec "prices.sale", achat avec "prices.buy"
                'price' => $sale ? $mainRow?->price : null,
                'wholesale' => $sale ? $mainRow?->price_wholesale : null,
                'buy' => $buy ? $mainRow?->price_buy : null,
                // une branche au moins vend a un autre prix que la reference (prix pas encore mis a jour ?)
                'price_differs' => $sale && $mainRow !== null
                    && $group->contains(fn (BranchStock $row) => $this->salePricesDiffer($row, $mainRow)),
            ];
        });

        return ['results' => $results, 'counts' => $counts, 'matched' => $kept->count()];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
