<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchTask;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    /**
     * Les branches que le compte voit. Pour le developpeur et le directeur de la societe, en plus : ce que chaque
     * branche voit du stock des autres (branch_access), modifiable parmi les branches qu'ils gerent.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::visibleTo($user)->orderBy('name')->get();
        $canAccess = $user->can('manage-branch-access');

        return view('branches.index', [
            'branches' => $branches,
            // ventes, clients... (agent 1.0.7) : ou en est chaque tache de chaque branche
            'tasks' => BranchTask::whereIn('branch_id', $branches->pluck('id'))->get()->groupBy('branch_id'),
            'agentVersion' => AgentDownloadController::version(),
            'managed' => $canAccess ? $user->managedBranches() : collect(),
            'access' => $canAccess
                ? $branches->mapWithKeys(fn (Branch $b) => [$b->id => BranchAccess::visibleFrom($b->id, 'stock')])
                : collect(),
        ]);
    }

    /**
     * Code a usage unique a saisir dans GescomAgent-Setup.exe : pour une nouvelle branche, ou pour reinstaller
     * l'agent d'une branche existante (nouveau PC, Windows reinstalle) : elle garde ses comptes et ses reglages.
     */
    public function installCode(Request $request): RedirectResponse
    {
        $forId = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        $for = $forId ? Branch::findOrFail($forId) : null;
        abort_if($for && ! $request->user()->seesBranch($for), 404);

        $code = InstallCode::generate($request->user()->id, $for?->id);
        Activity::log('install_code.created', $for, ['for' => $for ? 'reinstall' : 'new_branch']);

        return back()->with('install_code', $code)->with('install_code_branch', $for?->name);
    }

    /** Ce que la branche voit du stock des autres : seulement parmi les branches que l'auteur gere. */
    public function access(Request $request, Branch $branch): RedirectResponse
    {
        $managed = $request->user()->managedBranches()->pluck('id')->all();
        abort_unless(in_array($branch->id, $managed, true), 404);
        $visible = array_map('intval', $request->validate([
            'visible' => ['nullable', 'array'],
            'visible.*' => ['integer', Rule::in($managed)],
        ])['visible'] ?? []);

        $before = BranchAccess::visibleFrom($branch->id, 'stock');
        BranchAccess::set($branch->id, 'stock', $visible, $managed);
        $after = BranchAccess::visibleFrom($branch->id, 'stock');
        sort($before);
        sort($after);
        if ($before !== $after) {
            Activity::log('branch.access', $branch, ['module' => 'stock', 'visible' => [$before, $after]]);
        }

        return back()->with('status', __('branches.access_saved', ['name' => $branch->name]));
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeBranch($request, $branch);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => __('branches.name_required'),
        ]);
        $changes = Activity::diff($branch->only(array_keys($data)), $data);
        $branch->update($data);
        if ($changes !== []) {
            Activity::log('branch.updated', $branch, $changes);
        }

        return back()->with('status', __('common.saved'));
    }

    /** En-tete et pied des impressions de la branche (comme LIGNE1..5 et BAS1..2 de GC032 ; l'agent ne les lit pas encore). */
    public function printHeader(Request $request, Branch $branch): View
    {
        $this->authorizeBranch($request, $branch);

        return view('branches.print-header', ['branch' => $branch]);
    }

    public function savePrintHeader(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeBranch($request, $branch);
        $data = $request->validate([
            'print_header' => ['nullable', 'string', 'max:600'],
            'print_footer' => ['nullable', 'string', 'max:400'],
        ]);
        $changes = Activity::diff($branch->only(array_keys($data)), $data);
        $branch->update($data);
        if ($changes !== []) {
            Activity::log('branch.updated', $branch, $changes);
        }

        return redirect()->route('branches.index')->with('status', __('common.saved'));
    }

    /**
     * Branche principale = reference des prix : son prix s'affiche dans la recherche, et la fenetre "معاينة"
     * signale les branches dont le prix differe. Une seule a la fois ; un second clic retire le statut.
     */
    public function main(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeBranch($request, $branch);
        $makeMain = ! $branch->is_main;
        DB::transaction(function () use ($branch, $makeMain) {
            Branch::where('is_main', true)->update(['is_main' => false]);
            if ($makeMain) {
                $branch->forceFill(['is_main' => true])->save();
            }
        });
        Activity::log('branch.main', $branch, ['is_main' => [! $makeMain, $makeMain]]);

        return back()->with('status', __($makeMain ? 'branches.now_main' : 'branches.no_longer_main', ['name' => $branch->name]));
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeBranch($request, $branch);
        $name = $branch->name;
        Activity::log('branch.deleted', $branch, ['name' => $name]);
        $branch->delete();

        return back()->with('status', __('branches.deleted', ['name' => $name]));
    }

    /**
     * Bouton "مزامنة الآن" : l'agent renvoie tout le stock de la branche des qu'il recupere la demande (~10 s).
     * Agent trop ancien pour la demande "sync" : pas d'erreur, la page attend sa prochaine synchro automatique (statut waiting).
     */
    public function sync(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);
        if (! $branch->active) {
            return response()->json(['error' => __('branches.is_disabled')], 422);
        }
        // batch choisi par la page : une requete rejouee apres une coupure retrouve sa demande au lieu d'en recreer une
        $batch = $request->validate(['batch' => ['nullable', 'uuid']])['batch'] ?? (string) Str::uuid();
        $job = RefreshJob::where('batch', $batch)->where('branch_id', $branch->id)->where('type', 'sync')->first()
            ?? RefreshJob::create([
                'batch' => $batch,
                'type' => 'sync',
                'branch_id' => $branch->id,
                'code_key' => null,
                'status' => $branch->agentSupports('sync') ? 'pending' : 'waiting',
                'requested_by' => $request->user()->id,
            ]);

        return response()->json(['job' => $job->id, 'status' => $job->status, 'wait_seconds' => $job->timeoutSeconds()]);
    }

    public function syncStatus(Request $request, Branch $branch, RefreshJob $job): JsonResponse
    {
        $this->authorizeBranch($request, $branch);
        abort_unless($job->branch_id === $branch->id && $job->type === 'sync', 404);

        return response()->json([
            'status' => $job->displayStatus(),
            'error' => $job->result['error'] ?? null,
            'last_sync' => $branch->last_sync_at?->diffForHumans(),
            'items_count' => $branch->items_count,
        ]);
    }

    /** Une branche que le compte ne voit pas n'existe pas pour lui (404, comme une branche inconnue). */
    private function authorizeBranch(Request $request, Branch $branch): void
    {
        abort_unless($request->user()->seesBranch($branch), 404);
    }
}
