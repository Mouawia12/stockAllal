<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Partner;
use App\Support\AiProviderError;
use App\Support\AiProviders;
use App\Support\MergeScanner;
use App\Support\Reconciliation;
use App\Support\ReconciliationChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * "المطابقة" (reconcile.use) : on choisit le client (chez la branche qui vend) et le fournisseur (chez celle qui achete),
 * la page montre ce que Reconciliation trouve, et un chat ou l'IA connectee l'explique (ReconciliationChat).
 * Seulement entre deux branches dont le compte voit les ventes ; montants des ventes et des achats : reconcile.use
 * entraine prices.sale et prices.buy (Permissions).
 */
class ReconcileController extends Controller
{
    /** Le rapport d'une paire reste 2 minutes : les questions du chat portent sur le meme (cache de Claude compris). */
    private const REPORT_SECONDS = 120;

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        [$customer, $supplier] = $this->pair($request);
        $branches = Branch::where('active', true)->among($user->salesBranchIds())->orderBy('name')->get();

        return view('reconcile.index', [
            'branches' => $branches,
            'customer' => $customer,
            'supplier' => $supplier,
            'report' => $customer && $supplier ? self::report($customer, $supplier) : null,
            'provider' => MergeScanner::provider(),
            // sans l'agent 1.0.7, une branche n'envoie ni bons ni versements : rien a comparer
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
        ]);
    }

    /**
     * Recherche d'un client ou d'un fournisseur dans une branche (JSON, au plus 12). Sans mot : ceux dont le nom
     * ressemble a celui de l'autre branche (near), puis ceux qui ont le plus de bons.
     */
    public function partners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['required', 'integer'],
            'kind' => ['required', 'in:customer,supplier'],
            'q' => ['nullable', 'string', 'max:60'],
            'near' => ['nullable', 'integer'],
        ]);
        $branches = Branch::where('active', true)->among($request->user()->salesBranchIds());
        $branch = (clone $branches)->findOrFail($data['branch']);
        $near = isset($data['near']) ? (clone $branches)->find($data['near']) : null;
        $q = trim((string) ($data['q'] ?? ''));

        $query = Partner::where('branch_id', $branch->id)->where('kind', $data['kind']);
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $found = $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orderBy('name')->limit(12)->get(['id', 'code', 'name']);
        } else {
            $all = $query->get(['id', 'code', 'name']);
            $counts = $this->bonCounts($branch->id, $data['kind'], $all->pluck('code')->all());
            $found = $all->map(fn (Partner $p) => [$p, $near ? self::likeness((string) $near->name, $p) : 0, $counts[$p->code] ?? 0])
                ->sortByDesc(fn ($x) => [$x[1] >= 60 ? $x[1] : 0, $x[2]])
                ->take(8)->pluck(0)->values();
        }
        $counts ??= $this->bonCounts($branch->id, $data['kind'], $found->pluck('code')->all());

        return response()->json($found->map(fn (Partner $p) => [
            'id' => $p->id,
            'code' => trim((string) $p->code),
            'name' => $p->name ?? trim((string) $p->code),
            'bons' => $counts[$p->code] ?? 0,
        ])->values());
    }

    /** Une question au chat ; l'historique est garde par la page (sessionStorage), jamais sur le serveur. */
    public function chat(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        $request->validate([
            'ca' => ['required', 'integer'],
            'sb' => ['required', 'integer'],
            'messages' => ['required', 'array', 'max:'.(ReconciliationChat::MAX_MESSAGES * 2)],
        ]);
        [$customer, $supplier] = $this->pair($request);
        $messages = ReconciliationChat::messages((array) $request->input('messages'));
        if (! $customer || ! $supplier || $messages === null) {
            return response()->json(['error' => __('reconcile.chat.empty')], 422);
        }
        $ai = MergeScanner::provider();
        if (! $ai) {
            return response()->json(['error' => __('reconcile.chat.no_provider')], 409);
        }

        @set_time_limit(AiProviders::ASK_TIMEOUT + 30);
        $system = ReconciliationChat::system(self::report($customer, $supplier), app()->getLocale());
        try {
            $answer = AiProviders::chat($ai->provider, $ai->api_key, $ai->model, $system, $messages);
        } catch (AiProviderError $e) {
            $reason = array_key_exists($e->reason, trans('ai.errors')) ? $e->reason : 'other';

            // jamais 502 a 504 : fetchRetry referait la question, payee deux fois
            return response()->json(['error' => __("ai.errors.{$reason}", ['name' => AiProviders::name($ai->provider)]), 'detail' => $e->detail], 422);
        }

        return response()->json(['text' => $answer['text'], 'truncated' => $answer['truncated']]);
    }

    /**
     * Le client (ca) et le fournisseur (sb) demandes, s'ils se correspondent pour ce compte ; une paire donnee
     * mais interdite (autre branche, meme branche, mauvais type) : 404.
     *
     * @return array{0: ?Partner, 1: ?Partner}
     */
    private function pair(Request $request): array
    {
        $ca = $request->integer('ca');
        $sb = $request->integer('sb');
        if (! $ca || ! $sb) {
            return [null, null];
        }
        $customer = Partner::with('branch')->find($ca);
        $supplier = Partner::with('branch')->find($sb);
        abort_unless($customer && $supplier && Reconciliation::allowed($customer, $supplier, $request->user()->salesBranchIds()), 404);

        return [$customer, $supplier];
    }

    private static function report(Partner $customer, Partner $supplier): array
    {
        return Cache::remember("reconcile:{$customer->id}:{$supplier->id}", self::REPORT_SECONDS,
            fn () => Reconciliation::run($customer, $supplier));
    }

    /** @return array<string, int> code => nombre de bons (ventes d'un client, achats d'un fournisseur) */
    private function bonCounts(int $branchId, string $kind, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return Document::where('branch_id', $branchId)->where('kind', $kind === 'customer' ? 'sale' : 'purchase')
            ->whereIn('partner_code', $codes)->groupBy('partner_code')->selectRaw('partner_code, count(*) as n')
            ->pluck('n', 'partner_code')->map(fn ($n) => (int) $n)->all();
    }

    /** 0 a 100 : le nom de la branche dans celui du partenaire (100), sinon leur ressemblance. */
    private static function likeness(string $branchName, Partner $p): float
    {
        $needle = mb_strtolower(trim($branchName));
        $hay = mb_strtolower(trim(($p->name ?? '').' '.$p->code));
        if ($needle === '' || $hay === '') {
            return 0;
        }
        if (str_contains($hay, $needle)) {
            return 100;
        }
        similar_text($needle, $hay, $percent);

        return $percent;
    }
}
