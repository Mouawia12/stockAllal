<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ProductGroup;
use App\Models\RefreshJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockController extends Controller
{
    private const MAX_RESULTS = 100;

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

    /** Detail d'un produit dans chaque branche active (la fenetre "apercu"), avec l'etat d'un batch de mise a jour. */
    public function product(Request $request): JsonResponse
    {
        $key = BranchStock::keyFor((string) $request->query('code', ''));
        $codes = ProductGroup::codesOf($key);
        $batch = (string) $request->query('batch', '');

        $branches = Branch::where('active', true)->orderBy('name')->get();
        $rows = BranchStock::whereIn('code_key', $codes)->orderBy('code_key')->get()->groupBy('branch_id');
        $jobs = $batch !== '' ? RefreshJob::where('batch', $batch)->get() : collect();
        $jobsByBranch = $jobs->groupBy('branch_id');
        $main = $branches->firstWhere('is_main', true);

        $list = $branches->map(function (Branch $branch) use ($rows, $jobsByBranch) {
            return [
                'branch' => $branch->name,
                'main' => $branch->is_main,
                'online' => $branch->isOnline(),
                'last_seen' => $branch->last_seen_at?->diffForHumans(),
                'status' => $this->branchStatus($jobsByBranch->get($branch->id)),
                'rows' => $rows->get($branch->id, collect())->map(fn (BranchStock $row) => [
                    'code' => $row->code_raw,
                    'designation' => $row->designation,
                    'stock' => $row->stock,
                    'price' => $row->price,
                    // une synchro complete n'ecrit que les lignes changees : la valeur est confirmee a la derniere synchro
                    'updated' => collect([$row->updated_at, $branch->last_sync_at])->filter()->max()?->diffForHumans(),
                ])->values(),
            ];
        })->sortByDesc(fn (array $b) => $b['rows']->isEmpty() ? -INF : $b['rows']->max('stock'))->values();

        $done = $jobs->isNotEmpty()
            && $jobs->every(fn (RefreshJob $job) => ! in_array($job->displayStatus(), ['pending', 'taken'], true));

        return response()->json([
            'code' => $key,
            'codes' => $codes,
            // prix de reference : la fenetre signale les branches dont le prix en differe
            'main_price' => $main ? $this->mainPrice($rows->get($main->id, collect())) : null,
            'branches' => $list,
            'done' => $done,
        ]);
    }

    /**
     * Bouton "mettre a jour" : une demande par branche active, recuperee par chaque agent sous ~10 s.
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

        foreach (Branch::where('active', true)->pluck('id') as $branchId) {
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
        $main = Branch::main();

        return view($request->boolean('partial') ? 'stock._results' : 'stock.index', [
            'q' => $q,
            'terms' => $terms,
            'merge' => $merge,
            'mainBranch' => $main,
            'results' => $terms === [] ? collect() : $this->search($q, $terms, $main),
            'branchesTotal' => Branch::where('active', true)->count(),
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

    /** Prix de la branche principale ; si le groupe y a plusieurs codes, celui de sa ligne la mieux stockee. */
    private function mainPrice(Collection $mainRows): ?float
    {
        return $mainRows->sortByDesc('stock')->first()?->price;
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

    /** @param  list<string>  $terms */
    private function search(string $q, array $terms, ?Branch $main): Collection
    {
        $upper = mb_strtoupper(trim($q));
        $prefix = $this->escapeLike($upper).'%';
        $firstInCode = '%'.$this->escapeLike($terms[0]).'%';

        // ESCAPE '!' : meme syntaxe en MySQL et en SQLite (le backslash ne l'est pas)
        $query = BranchStock::query()
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->where('branches.active', true);
        foreach ($terms as $term) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(fn ($w) => $w
                ->whereRaw("branch_stock.code_key LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("UPPER(branch_stock.designation) LIKE ? ESCAPE '!'", [$like]));
        }
        $keys = $query
            ->groupBy('branch_stock.code_key')
            // code exact, puis code qui commence par la recherche, puis code qui la contient, puis designation seule
            ->orderByRaw("CASE WHEN branch_stock.code_key = ? THEN 0 WHEN branch_stock.code_key LIKE ? ESCAPE '!' THEN 1 WHEN branch_stock.code_key LIKE ? ESCAPE '!' THEN 2 ELSE 3 END", [$upper, $prefix, $firstInCode])
            ->orderBy('branch_stock.code_key')
            ->limit(self::MAX_RESULTS)
            ->pluck('branch_stock.code_key');

        // codes fusionnes : une ligne par groupe, affichee sous le premier de ses codes trouve par la recherche
        $expanded = ProductGroup::expand($keys);
        $units = $keys->map(fn (string $key) => ['code' => $key, 'codes' => $expanded[$key]])
            ->unique(fn (array $unit) => $unit['codes'][0])
            ->values();

        $rows = BranchStock::query()
            ->whereIn('code_key', $units->pluck('codes')->flatten())
            ->whereHas('branch', fn ($b) => $b->where('active', true))
            ->get()
            ->groupBy('code_key');

        return $units->map(function (array $unit) use ($rows, $main) {
            $group = collect($unit['codes'])->flatMap(fn (string $code) => $rows->get($code, collect()));
            $price = $main ? $this->mainPrice($group->where('branch_id', $main->id)) : null;

            return (object) [
                'code' => $unit['code'],
                // les autres codes du groupe, vide si le code n'est pas fusionne
                'merged' => array_values(array_diff($unit['codes'], [$unit['code']])),
                // la designation de la branche qui a le plus de stock
                'designation' => $group->sortByDesc('stock')->first()?->designation,
                'total' => round($group->sum('stock'), 2),
                'branches' => $group->pluck('branch_id')->unique()->count(),
                'price' => $price,
                // une branche au moins vend a un autre prix que la principale (prix pas encore mis a jour ?)
                'price_differs' => $price !== null
                    && $group->contains(fn (BranchStock $row) => $row->price !== null && abs($row->price - $price) >= 0.005),
            ];
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
