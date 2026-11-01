<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('branches.index', [
            'branches' => Branch::orderBy('name')->get(),
            'agentVersion' => AgentDownloadController::version(),
        ]);
    }

    /** Code a usage unique a saisir dans install.ps1 sur le serveur de la nouvelle branche. */
    public function installCode(Request $request): RedirectResponse
    {
        return back()->with('install_code', InstallCode::generate($request->user()->id));
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'اسم الفرع مطلوب',
        ]);
        $branch->update($data);

        return back()->with('status', 'تم الحفظ');
    }

    /**
     * Branche principale = reference des prix : son prix s'affiche dans la recherche, et la fenetre "معاينة"
     * signale les branches dont le prix differe. Une seule a la fois ; un second clic retire le statut.
     */
    public function main(Branch $branch): RedirectResponse
    {
        $makeMain = ! $branch->is_main;
        DB::transaction(function () use ($branch, $makeMain) {
            Branch::where('is_main', true)->update(['is_main' => false]);
            if ($makeMain) {
                $branch->forceFill(['is_main' => true])->save();
            }
        });

        return back()->with('status', $makeMain
            ? "«{$branch->name}» هو الفرع الرئيسي الآن: سعره يظهر في البحث"
            : "«{$branch->name}» لم يعد الفرع الرئيسي");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $name = $branch->name;
        $branch->delete();

        return back()->with('status', "تم حذف الفرع «{$name}» ومخزونه");
    }

    /** Bouton "مزامنة الآن" : l'agent renvoie tout le stock de la branche des qu'il recupere la demande (~10 s). */
    public function sync(Request $request, Branch $branch): JsonResponse
    {
        if (! $branch->active) {
            return response()->json(['error' => 'الفرع معطّل'], 422);
        }
        // batch choisi par la page : une requete rejouee apres une coupure retrouve sa demande au lieu d'en recreer une
        $batch = $request->validate(['batch' => ['nullable', 'uuid']])['batch'] ?? (string) Str::uuid();
        $existing = RefreshJob::where('batch', $batch)->where('branch_id', $branch->id)->where('type', 'sync')->first();
        if ($existing) {
            return response()->json(['job' => $existing->id]);
        }

        $job = RefreshJob::create([
            'batch' => $batch,
            'type' => 'sync',
            'branch_id' => $branch->id,
            'code_key' => null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        return response()->json(['job' => $job->id]);
    }

    public function syncStatus(Branch $branch, RefreshJob $job): JsonResponse
    {
        abort_unless($job->branch_id === $branch->id && $job->type === 'sync', 404);

        return response()->json([
            'status' => $job->displayStatus(),
            'error' => $job->result['error'] ?? null,
            'last_sync' => $branch->last_sync_at?->diffForHumans(),
            'items_count' => $branch->items_count,
        ]);
    }
}
