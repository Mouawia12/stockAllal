<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ReconciliationRun;
use App\Models\RefreshJob;
use App\Support\AiProviderError;
use App\Support\AiProviders;
use App\Support\MergeScanner;
use App\Support\Reconciliation;
use App\Support\ReconciliationChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "المطابقة" (reconcile.use) : on choisit le client (chez la branche qui vend) et le fournisseur (chez celle qui achete),
 * la page lance l'analyse de Reconciliation etape par etape, la garde (ReconciliationRun : on y revient, l'historique
 * de la paire, ce qui a change depuis la precedente), et un chat ou l'IA connectee l'explique (ReconciliationChat).
 * "إعادة الفحص" demande d'abord aux deux branches de renvoyer leurs bons et versements (demande "data", agent 1.0.10),
 * attend leur reponse, puis analyse. Seulement entre deux branches dont le compte voit les ventes ; montants des ventes
 * et des achats : reconcile.use entraine prices.sale et prices.buy (Permissions).
 */
class ReconcileController extends Controller
{
    /** Une analyse commencee garde son etat 15 minutes entre deux etapes. */
    private const RUN_SECONDS = 900;

    /** Les jeux que chaque cote renvoie pour "إعادة الفحص". */
    private const SYNC_SETS = [
        'customer' => ['sales', 'customer_payments', 'customers'],
        'supplier' => ['purchases', 'supplier_payments', 'suppliers'],
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        [$customer, $supplier] = $this->pair($request);
        $branches = Branch::where('active', true)->among($user->salesBranchIds())->orderBy('name')->get();
        $provider = MergeScanner::provider();

        $runs = collect();
        $run = null;
        if ($customer && $supplier) {
            // l'historique de la paire, sans les rapports (lourds) ; le rapport ouvert : ?r= ou le dernier
            $runs = ReconciliationRun::with('user:id,name')->where('customer_id', $customer->id)->where('supplier_id', $supplier->id)
                ->orderByDesc('id')->limit(ReconciliationRun::KEEP)->get(['id', 'user_id', 'gap', 'problems', 'synced', 'created_at']);
            if (! $request->boolean('again')) {
                $id = $request->integer('r') ?: $runs->first()?->id;
                abort_if($request->integer('r') && ! $runs->contains('id', $id), 404);
                $run = $id ? ReconciliationRun::find($id) : null;
            }
        }
        $sync = $request->boolean('sync');

        return view('reconcile.index', [
            'branches' => $branches,
            'customer' => $customer,
            'supplier' => $supplier,
            'run' => $run,
            'report' => $run?->report,
            'runs' => $runs,
            'latest' => $runs->first()?->id,
            // pas d'analyse gardee (ou "إعادة الفحص") : la page lance l'analyse, etape par etape, avec sa barre
            'analyze' => $customer && $supplier && $run === null,
            'steps' => [...($sync ? ['sync'] : []), ...Reconciliation::STEPS, ...($provider ? ['ai'] : [])],
            'provider' => $provider,
            'recent' => $customer ? collect() : $this->recent($request),
            // sans l'agent 1.0.7, une branche n'envoie ni bons ni versements : rien a comparer
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
        ]);
    }

    /**
     * Une etape de l'analyse (Reconciliation::STEPS, puis "ai" si une IA est connectee). La page les demande une a
     * une : chaque reponse dit ce que l'etape a trouve, la suivante et l'avancement. "load" recommence l'analyse ;
     * la derniere garde le rapport (ReconciliationRun, compare au precedent) et donne son numero (run).
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
            'run' => ['nullable', 'integer'],
        ]);
        [$customer, $supplier] = $this->pair($request);
        abort_unless($customer && $supplier, 404);
        $step = $data['step'];
        $runKey = "reconcile:run:{$user->id}:{$customer->id}:{$supplier->id}";
        $total = count(Reconciliation::STEPS) + (MergeScanner::provider() ? 1 : 0);

        if ($step === 'ai') {
            $saved = ReconciliationRun::where('id', (int) ($data['run'] ?? 0))
                ->where('customer_id', $customer->id)->where('supplier_id', $supplier->id)->first();

            return $saved ? $this->analyzeWithAi($saved) : response()->json(['restart' => true], 409);
        }
        // l'etat entre deux etapes est un tableau : le cache ne relit aucun objet (cache.serializable_classes)
        $run = $step === 'load' ? Reconciliation::start($customer, $supplier) : Reconciliation::resume(Cache::get($runKey));
        if ($run === null || $run->next() !== $step) {
            return response()->json(['restart' => true], 409);
        }
        @set_time_limit(120);
        $found = $run->step($step);
        $next = $run->next();
        $saved = null;
        if ($next === null) {
            $saved = $this->save($run->report(), $customer, $supplier, $user->id, $request->boolean('synced'));
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
        ] + ($saved ? ['run' => $saved->id] : []));
    }

    /**
     * "إعادة الفحص" : demande aux deux branches de renvoyer maintenant les bons, versements et partenaires de la paire,
     * depuis un mois avant son premier bon. Agent d'avant 1.0.10 ou branche hors ligne : pas de demande, la page le dit
     * (ses donnees arrivent d'elles-memes : 7 derniers jours toutes les 5 minutes, 60 jours chaque heure, tout la nuit).
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        $request->validate(['ca' => ['required', 'integer'], 'sb' => ['required', 'integer']]);
        [$customer, $supplier] = $this->pair($request);
        abort_unless($customer && $supplier, 404);

        $first = collect([
            Document::where('branch_id', $customer->branch_id)->where('kind', 'sale')->where('partner_code', $customer->code)->min('date'),
            Document::where('branch_id', $supplier->branch_id)->where('kind', 'purchase')->where('partner_code', $supplier->code)->min('date'),
            Payment::where('branch_id', $customer->branch_id)->where('partner_kind', 'customer')->where('partner_code', $customer->code)->min('date'),
            Payment::where('branch_id', $supplier->branch_id)->where('partner_kind', 'supplier')->where('partner_code', $supplier->code)->min('date'),
        ])->filter()->map(fn ($d) => substr((string) $d, 0, 10))->min();
        $from = $first ? Carbon::parse($first)->subDays(Reconciliation::WINDOW_DAYS)->toDateString() : now()->subDays(60)->toDateString();
        $to = now()->toDateString();
        $batch = (string) Str::uuid();

        $out = [];
        foreach ([$customer, $supplier] as $partner) {
            $branch = $partner->branch;
            $row = ['branch' => $branch->name, 'job' => null];
            if (! $branch->agentSupports('data')) {
                $row['note'] = __('reconcile.sync.old', ['branch' => $branch->name, 'version' => Branch::AGENT_FEATURES['data']]);
            } elseif (! $branch->isOnline()) {
                $row['note'] = __('reconcile.sync.offline', ['branch' => $branch->name]);
            } else {
                $row['job'] = RefreshJob::create([
                    'batch' => $batch, 'type' => 'data', 'branch_id' => $branch->id, 'status' => 'pending', 'requested_by' => $user->id,
                    'params' => ['from' => $from, 'to' => $to, 'sets' => self::SYNC_SETS[$partner->kind]],
                ])->id;
            }
            $out[] = $row;
        }

        return response()->json(['batch' => $batch, 'from' => $from, 'branches' => $out]);
    }

    /** Ou en sont les demandes "data" d'un "إعادة الفحص" : pending, taken, done, error ou timeout (10 minutes). */
    public function syncStatus(Request $request, string $batch): JsonResponse
    {
        $ids = $request->user()->salesBranchIds();
        $jobs = RefreshJob::with('branch')->where('batch', $batch)->where('type', 'data')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->get();
        abort_if($jobs->isEmpty(), 404);

        return response()->json(['jobs' => $jobs->map(fn (RefreshJob $j) => [
            'job' => $j->id,
            'branch' => $j->branch->name,
            'status' => $j->displayStatus(),
            'error' => $j->result['error'] ?? null,
            'seconds' => (int) $j->created_at->diffInSeconds(now()),
        ])->values()]);
    }

    /** La derniere etape : l'IA lit le resultat et ecrit sa conclusion, gardee avec lui. Un echec n'empeche pas le resultat. */
    private function analyzeWithAi(ReconciliationRun $saved): JsonResponse
    {
        $report = $saved->report;
        $ai = MergeScanner::provider();
        if (! $ai) {
            return response()->json(['step' => 'ai', 'note' => __('reconcile.chat.no_provider'), 'next' => null, 'percent' => 100, 'run' => $saved->id]);
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
        $saved->forceFill(['report' => $report])->save();

        return response()->json(['step' => 'ai', 'note' => $note, 'next' => null, 'percent' => 100, 'run' => $saved->id, 'failed' => isset($report['ai']['error'])]);
    }

    /** Garde une analyse, avec ce qui a change depuis la precedente de la paire ; seules les KEEP dernieres restent. */
    private function save(array $report, Partner $customer, Partner $supplier, int $userId, bool $synced): ReconciliationRun
    {
        $previous = ReconciliationRun::where('customer_id', $customer->id)->where('supplier_id', $supplier->id)->orderByDesc('id')->first();
        $report = Reconciliation::compare($report, $previous?->report, $previous ? ['previous_id' => $previous->id, 'previous_at' => $previous->created_at->toIso8601String()] : []);
        $run = ReconciliationRun::forceCreate([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id,
            'branch_a_id' => $customer->branch_id, 'branch_b_id' => $supplier->branch_id,
            'user_id' => $userId, 'gap' => $report['gap'], 'synced' => $synced,
            'problems' => count($report['problems']) + $report['more_problems'], 'report' => $report,
        ]);
        ReconciliationRun::prune($customer->id, $supplier->id);

        return $run;
    }

    /**
     * Les dernieres paires analysees que ce compte peut ouvrir (ses deux branches visibles), la plus recente d'abord.
     *
     * @return Collection<int, ReconciliationRun>
     */
    private function recent(Request $request): Collection
    {
        $ids = $request->user()->salesBranchIds();

        return ReconciliationRun::with(['customer.branch', 'supplier.branch', 'user:id,name'])
            ->whereNotNull('customer_id')->whereNotNull('supplier_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_a_id', $ids)->whereIn('branch_b_id', $ids))
            ->orderByDesc('id')->limit(200)->get(['id', 'customer_id', 'supplier_id', 'user_id', 'gap', 'problems', 'synced', 'created_at'])
            ->filter(fn (ReconciliationRun $r) => $r->customer && $r->supplier && Reconciliation::allowed($r->customer, $r->supplier, $ids))
            ->unique(fn (ReconciliationRun $r) => $r->customer_id.':'.$r->supplier_id)
            ->take(15)->values();
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

    /** Une question au chat sur un rapport garde (run) ; l'historique est garde par la page (sessionStorage). */
    public function chat(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('prices.sale') && $user->can('prices.buy'), 403);
        $request->validate([
            'ca' => ['required', 'integer'],
            'sb' => ['required', 'integer'],
            'run' => ['nullable', 'integer'],
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
        $report = $this->report($customer, $supplier, $request->integer('run'));
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

    /** Le rapport du chat : l'analyse demandee (run) de cette paire, sinon la derniere, sinon une analyse sans l'IA. */
    private function report(Partner $customer, Partner $supplier, int $runId): array
    {
        $runs = ReconciliationRun::where('customer_id', $customer->id)->where('supplier_id', $supplier->id);
        $saved = ($runId ? (clone $runs)->find($runId) : null) ?? $runs->orderByDesc('id')->first();

        return $saved?->report ?? Reconciliation::run($customer, $supplier);
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
