<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\RefreshJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockController extends Controller
{
    private const MAX_RESULTS = 100;

    /** Recherche : une ligne par code_key, tous les branches confondues. */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('stock.index', [
            'q' => $q,
            'results' => $q === '' ? collect() : $this->search($q),
            'branchesTotal' => Branch::where('active', true)->count(),
        ]);
    }

    /** Detail d'un produit dans chaque branche active (la fenetre "apercu"), avec l'etat d'un batch de mise a jour. */
    public function product(Request $request): JsonResponse
    {
        $key = BranchStock::keyFor((string) $request->query('code', ''));
        $batch = (string) $request->query('batch', '');

        $branches = Branch::where('active', true)->orderBy('name')->get();
        $rows = BranchStock::where('code_key', $key)->get()->groupBy('branch_id');
        $jobs = $batch !== '' ? RefreshJob::where('batch', $batch)->get()->keyBy('branch_id') : collect();

        $list = $branches->map(function (Branch $branch) use ($rows, $jobs) {
            $job = $jobs->get($branch->id);

            return [
                'branch' => $branch->name,
                'online' => $branch->isOnline(),
                'last_seen' => $branch->last_seen_at?->diffForHumans(),
                'status' => $job?->displayStatus(),
                'rows' => $rows->get($branch->id, collect())->map(fn (BranchStock $row) => [
                    'code' => $row->code_raw,
                    'designation' => $row->designation,
                    'stock' => $row->stock,
                    'updated' => $row->updated_at?->diffForHumans(),
                ])->values(),
            ];
        })->sortByDesc(fn (array $b) => $b['rows']->isEmpty() ? -INF : $b['rows']->max('stock'))->values();

        $done = $jobs->isNotEmpty()
            && $jobs->every(fn (RefreshJob $job) => ! in_array($job->displayStatus(), ['pending', 'taken'], true));

        return response()->json(['code' => $key, 'branches' => $list, 'done' => $done]);
    }

    /** Bouton "mettre a jour" : une demande par branche active, recuperee par chaque agent sous ~10 s. */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $key = BranchStock::keyFor($data['code']);
        $batch = (string) Str::uuid();

        foreach (Branch::where('active', true)->pluck('id') as $branchId) {
            RefreshJob::create([
                'batch' => $batch,
                'branch_id' => $branchId,
                'code_key' => $key,
                'status' => 'pending',
                'requested_by' => $request->user()?->id,
            ]);
        }

        return response()->json(['batch' => $batch]);
    }

    private function search(string $q): Collection
    {
        $upper = mb_strtoupper($q);
        $contains = '%'.$this->escapeLike($upper).'%';
        $prefix = $this->escapeLike($upper).'%';

        // ESCAPE '!' : meme syntaxe en MySQL et en SQLite (le backslash ne l'est pas)
        $keys = BranchStock::query()
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->where('branches.active', true)
            ->where(fn ($w) => $w
                ->whereRaw("branch_stock.code_key LIKE ? ESCAPE '!'", [$contains])
                ->orWhereRaw("UPPER(branch_stock.designation) LIKE ? ESCAPE '!'", [$contains]))
            ->groupBy('branch_stock.code_key')
            // code exact d'abord, puis les codes qui commencent par la recherche, puis le reste
            ->orderByRaw("CASE WHEN branch_stock.code_key = ? THEN 0 WHEN branch_stock.code_key LIKE ? ESCAPE '!' THEN 1 ELSE 2 END", [$upper, $prefix])
            ->orderBy('branch_stock.code_key')
            ->limit(self::MAX_RESULTS)
            ->pluck('branch_stock.code_key');

        $rows = BranchStock::query()
            ->whereIn('code_key', $keys)
            ->whereHas('branch', fn ($b) => $b->where('active', true))
            ->get()
            ->groupBy('code_key');

        return $keys->map(function (string $key) use ($rows) {
            $group = $rows->get($key, collect());

            return (object) [
                'code' => $key,
                // la designation de la branche qui a le plus de stock
                'designation' => $group->sortByDesc('stock')->first()?->designation,
                'total' => round($group->sum('stock'), 2),
                'branches' => $group->pluck('branch_id')->unique()->count(),
            ];
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
