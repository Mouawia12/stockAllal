<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\MergeDismissal;
use App\Models\MergeScan;
use App\Models\MergeSuggestion;
use App\Models\ProductGroup;
use App\Support\MergeScanner;
use App\Support\StockState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "اقتراحات الذكاء الاصطناعي" de la page "دمج الأصناف" (merge.manage) : lancer une analyse (MergeScanner), suivre
 * son avancement, puis decider : tout fusionner, cocher des groupes, retirer un code d'un groupe, fusionner groupe
 * par groupe, ou dire "ليسا نفس الصنف" (jamais repropose). Un compte ne voit que les analyses dont il voit toutes
 * les branches ; leurs articles ne montrent que ces branches.
 */
class MergeAiController extends Controller
{
    /** Filtres du rapport (?c=) : la confiance de l'IA ; et (?s=) ce qui est deja decide. */
    public const CONFIDENCE_FILTERS = ['all', 'high', 'medium', 'low'];

    public const STATUS_FILTERS = ['open', 'merged', 'dismissed'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $scan = MergeScan::latest('id')->limit(20)->get()->first(fn (MergeScan $s) => $s->visibleTo($user));
        $confidence = in_array($request->query('c'), self::CONFIDENCE_FILTERS, true) ? $request->query('c') : 'all';
        $status = in_array($request->query('s'), self::STATUS_FILTERS, true) ? $request->query('s') : 'open';

        $suggestions = collect();
        $counts = ['confidence' => [], 'status' => []];
        if ($scan) {
            $all = $scan->suggestions()->get();
            $counts['status'] = $all->countBy('status')->all();
            $counts['confidence'] = $all->where('status', $status)->countBy('confidence')->all();
            $order = array_flip(MergeSuggestion::CONFIDENCES);
            $suggestions = $all->where('status', $status)
                ->when($confidence !== 'all', fn ($c) => $c->where('confidence', $confidence))
                ->sortBy(fn (MergeSuggestion $s) => [$order[$s->confidence], $s->source === 'code' ? 0 : 1, $s->name])
                ->values();
        }

        return view('merge.ai', [
            'scan' => $scan,
            'provider' => MergeScanner::provider(),
            'branchCount' => count(MergeScanner::branchIds($user)),
            'confidence' => $confidence,
            'status' => $status,
            'counts' => $counts,
            'suggestions' => $suggestions,
            'items' => $scan ? $this->items($scan, $suggestions) : [],
            'branches' => $scan ? Branch::whereIn('id', $scan->branch_ids)->orderBy('name')->get()->keyBy('id') : collect(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $running = MergeScan::where('status', 'running')->get()->first(fn (MergeScan $s) => $s->visibleTo($request->user()));
        if ($running) {
            return redirect()->route('merge.ai');
        }
        $ai = MergeScanner::provider((string) $request->input('provider'));
        if (! $ai) {
            return back()->withErrors(['provider' => __('merge_ai.no_provider')]);
        }
        if (count(MergeScanner::branchIds($request->user())) < 2) {
            return back()->withErrors(['provider' => __('merge_ai.need_two')]);
        }

        MergeScanner::start($request->user(), $ai, $request->boolean('deep'));

        return redirect()->route('merge.ai');
    }

    /**
     * Un lot de plus (la page l'appelle tant que l'analyse tourne) ; renvoie l'avancement.
     * busy : un autre appel (la tache planifiee, un autre onglet) traite deja un lot.
     */
    public function step(Request $request, MergeScan $scan): JsonResponse
    {
        abort_unless($scan->visibleTo($request->user()), 404);
        @set_time_limit(200);
        $worked = $scan->running() && MergeScanner::step($scan);
        $scan->refresh();

        return response()->json([
            'status' => $scan->status,
            'busy' => $scan->running() && ! $worked,
            'progress' => $scan->progress(),
            'done' => $scan->batches_done + $scan->batches_failed,
            'batches' => $scan->batches,
            'found' => $scan->suggestions()->count(),
        ]);
    }

    public function cancel(Request $request, MergeScan $scan): RedirectResponse
    {
        abort_unless($scan->visibleTo($request->user()), 404);
        if ($scan->running()) {
            $scan->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        }

        return redirect()->route('merge.ai');
    }

    /** Reprendre une analyse arretee (cle refusee puis corrigee, credit epuise puis recharge, ou annulee). */
    public function resume(Request $request, MergeScan $scan): RedirectResponse
    {
        abort_unless($scan->visibleTo($request->user()), 404);
        if (in_array($scan->status, ['failed', 'cancelled'], true) && $scan->batchRows()->where('status', 'pending')->exists()) {
            $ai = MergeScanner::provider($scan->provider);
            if (! $ai) {
                return back()->withErrors(['provider' => __('merge_ai.no_provider')]);
            }
            $scan->forceFill(['status' => 'running', 'error' => null, 'error_detail' => null, 'finished_at' => null, 'provider' => $ai->provider, 'model' => $ai->model])->save();
        }

        return redirect()->route('merge.ai');
    }

    /**
     * Fusionner : les groupes coches (groups[]), ou un seul (only), chacun avec les codes laisses coches (codes[id][]).
     * Un groupe dont il ne reste qu'un article coche n'est pas fusionne.
     */
    public function merge(Request $request, MergeScan $scan): RedirectResponse
    {
        abort_unless($scan->visibleTo($request->user()), 404);
        $data = $request->validate([
            'groups' => ['array'],
            'groups.*' => ['integer'],
            'only' => ['nullable', 'integer'],
            'codes' => ['array'],
        ]);
        $ids = isset($data['only']) ? [(int) $data['only']] : array_map('intval', $data['groups'] ?? []);
        if ($ids === []) {
            return back()->withErrors(['groups' => __('merge_ai.pick_groups')]);
        }

        $merged = $skipped = 0;
        foreach ($scan->suggestions()->whereIn('id', $ids)->where('status', 'open')->get() as $suggestion) {
            // seuls les codes de la proposition comptent : la page ne peut pas en ajouter d'autres
            $kept = array_map(fn ($c) => BranchStock::keyFor((string) $c), (array) ($request->input("codes.{$suggestion->id}") ?? []));
            $units = array_values(array_filter($suggestion->units, fn (array $keys) => array_intersect($keys, $kept) !== []));
            if (count($units) < 2) {
                $skipped++;

                continue;
            }
            ProductGroup::merge(array_merge(...$units), $request->user()->id);
            $suggestion->forceFill(['status' => 'merged', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'units' => $units])->save();
            $merged++;
        }

        return back()->with('status', trans_choice('merge_ai.merged', $merged, ['count' => $merged])
            .($skipped ? ' '.trans_choice('merge_ai.skipped', $skipped, ['count' => $skipped]) : ''));
    }

    /** "ليسا نفس الصنف" : la proposition est rangee, et ses paires de codes ne seront plus jamais proposees ensemble. */
    public function dismiss(Request $request, MergeScan $scan, MergeSuggestion $suggestion): RedirectResponse
    {
        abort_unless($scan->visibleTo($request->user()) && $suggestion->merge_scan_id === $scan->id, 404);
        if ($suggestion->status === 'open') {
            MergeDismissal::remember($suggestion->codes(), $request->user()->id);
            $suggestion->forceFill(['status' => 'dismissed', 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();
        }

        return back()->with('status', __('merge_ai.dismissed'));
    }

    /** Remettre une proposition rangee par erreur dans la liste (et l'oublier dans les paires refusees). */
    public function reopen(Request $request, MergeScan $scan, MergeSuggestion $suggestion): RedirectResponse
    {
        abort_unless($scan->visibleTo($request->user()) && $suggestion->merge_scan_id === $scan->id, 404);
        if ($suggestion->status === 'dismissed') {
            $codes = $suggestion->codes();
            $pairs = [];
            foreach ($codes as $i => $a) {
                foreach (array_slice($codes, $i + 1) as $b) {
                    $pairs[] = MergeDismissal::pair($a, $b);
                }
            }
            MergeDismissal::whereIn('pair', $pairs)->delete();
            $suggestion->forceFill(['status' => 'open', 'decided_by' => null, 'decided_at' => null])->save();
        }

        return back()->with('status', __('merge_ai.reopened'));
    }

    /**
     * Pour chaque code des propositions affichees, ses lignes dans les branches de l'analyse : designation et stock.
     *
     * @return array<string, list<array{branch_id: int, code: string, designation: ?string, stock: float, state: string}>>
     */
    private function items(MergeScan $scan, $suggestions): array
    {
        $codes = $suggestions->flatMap(fn (MergeSuggestion $s) => $s->codes())->unique()->values();
        if ($codes->isEmpty()) {
            return [];
        }
        $rows = BranchStock::whereIn('branch_id', $scan->branch_ids)->whereIn('code_key', $codes)
            ->whereIn('branch_id', Branch::where('active', true)->pluck('id'))
            ->get(['branch_id', 'code_key', 'code_raw', 'designation', 'stock', 'stock_alert', 'stock_max']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->code_key][] = [
                'branch_id' => $r->branch_id,
                'code' => trim((string) $r->code_raw),
                'designation' => $r->designation,
                'stock' => (float) $r->stock,
                'state' => StockState::of((float) $r->stock, $r->stock_alert, $r->stock_max),
            ];
        }

        return $out;
    }
}
