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
    /** Le resultat d'une analyse reste 30 minutes (les questions du chat portent sur lui, cache de Claude compris). */
    private const REPORT_SECONDS = 1800;

    /** Une analyse commencee garde son etat 15 minutes entre deux etapes. */
    private const RUN_SECONDS = 900;

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        [$customer, $supplier] = $this->pair($request);
        $branches = Branch::where('active', true)->among($user->salesBranchIds())->orderBy('name')->get();
        // pas de resultat recent (ou "إعادة التحليل") : la page lance l'analyse, etape par etape, avec sa barre
        $report = $customer && $supplier && ! $request->boolean('again') ? Cache::get(self::reportKey($customer, $supplier)) : null;
        $provider = MergeScanner::provider();

        return view('reconcile.index', [
            'branches' => $branches,
            'customer' => $customer,
            'supplier' => $supplier,
            'report' => $report,
            'analyze' => $customer && $supplier && $report === null,
            'steps' => [...Reconciliation::STEPS, ...($provider ? ['ai'] : [])],
            'provider' => $provider,
            // sans l'agent 1.0.7, une branche n'envoie ni bons ni versements : rien a comparer
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
        ]);
    }

    /**
     * Une etape de l'analyse (Reconciliation::STEPS, puis "ai" si une IA est connectee). La page les demande une a
     * une : chaque reponse dit ce que l'etape a trouve, la suivante et l'avancement. "load" recommence l'analyse.
     * 409 restart : l'etat est perdu (15 minutes sans etape, ou une autre analyse) : la page recommence.
     */
    public function analyze(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        $data = $request->validate([
            'ca' => ['required', 'integer'],
            'sb' => ['required', 'integer'],
            'step' => ['required', 'in:'.implode(',', [...Reconciliation::STEPS, 'ai'])],
        ]);
        [$customer, $supplier] = $this->pair($request);
        abort_unless($customer && $supplier, 404);
        $step = $data['step'];
        $runKey = "reconcile:run:{$user->id}:{$customer->id}:{$supplier->id}";
        $total = count(Reconciliation::STEPS) + (MergeScanner::provider() ? 1 : 0);

        if ($step === 'ai') {
            return $this->analyzeWithAi($customer, $supplier, $total);
        }
        // l'etat entre deux etapes est un tableau : le cache ne relit aucun objet (cache.serializable_classes)
        $run = $step === 'load' ? Reconciliation::start($customer, $supplier) : Reconciliation::resume(Cache::get($runKey));
        if ($run === null || $run->next() !== $step) {
            return response()->json(['restart' => true], 409);
        }
        @set_time_limit(120);
        $found = $run->step($step);
        $next = $run->next();
        if ($next === null) {
            Cache::put(self::reportKey($customer, $supplier), $run->report(), self::REPORT_SECONDS);
            Cache::forget($runKey);
            $next = $total > count(Reconciliation::STEPS) ? 'ai' : null;
        } else {
            Cache::put($runKey, $run->state(), self::RUN_SECONDS);
        }
        $done = array_search($step, Reconciliation::STEPS, true) + 1;

        return response()->json([
            'step' => $step,
            'note' => __("reconcile.found.{$step}", $found),
            'next' => $next,
            'percent' => (int) round(100 * $done / $total),
        ]);
    }

    /** La derniere etape : l'IA lit le resultat et ecrit sa conclusion, gardee avec lui. Un echec n'empeche pas le resultat. */
    private function analyzeWithAi(Partner $customer, Partner $supplier, int $total): JsonResponse
    {
        $key = self::reportKey($customer, $supplier);
        $report = Cache::get($key);
        if ($report === null) {
            return response()->json(['restart' => true], 409);
        }
        $ai = MergeScanner::provider();
        if (! $ai) {
            return response()->json(['step' => 'ai', 'note' => __('reconcile.chat.no_provider'), 'next' => null, 'percent' => 100]);
        }
        @set_time_limit(AiProviders::ASK_TIMEOUT + 30);
        $system = ReconciliationChat::system($report, app()->getLocale());
        try {
            $answer = AiProviders::chat($ai->provider, $ai->api_key, $ai->model, $system, [['role' => 'user', 'content' => ReconciliationChat::ANALYZE]], 8000);
            $report['ai'] = ['text' => $answer['text'], 'truncated' => $answer['truncated'], 'provider' => $ai->provider, 'model' => $ai->model, 'at' => now()->toIso8601String()];
            $note = __('reconcile.found.ai', ['name' => AiProviders::name($ai->provider)]);
        } catch (AiProviderError $e) {
            $reason = array_key_exists($e->reason, trans('ai.errors')) ? $e->reason : 'other';
            $note = __("ai.errors.{$reason}", ['name' => AiProviders::name($ai->provider)]);
            $report['ai'] = ['error' => $note];
        }
        Cache::put($key, $report, self::REPORT_SECONDS);

        return response()->json(['step' => 'ai', 'note' => $note, 'next' => null, 'percent' => 100, 'failed' => isset($report['ai']['error'])]);
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
        $report = self::report($customer, $supplier);
        $system = ReconciliationChat::system($report, app()->getLocale());
        try {
            $answer = AiProviders::chat($ai->provider, $ai->api_key, $ai->model, $system, ReconciliationChat::withAnalysis($report, $messages));
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

    /** Le resultat de la derniere analyse de la paire ; sans analyse recente (chat ouvert longtemps), refait sans l'IA. */
    private static function report(Partner $customer, Partner $supplier): array
    {
        return Cache::remember(self::reportKey($customer, $supplier), self::REPORT_SECONDS,
            fn () => Reconciliation::run($customer, $supplier));
    }

    private static function reportKey(Partner $customer, Partner $supplier): string
    {
        return "reconcile:report:{$customer->id}:{$supplier->id}";
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
