<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ReconciliationRun;
use App\Models\RefreshJob;
use App\Models\User;
use App\Support\Reconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "المطابقة" garde chaque analyse (ReconciliationRun) : on revient a un rapport, l'historique de la paire, et une
 * nouvelle analyse dit ce qui a ete corrige, ce qui reste et ce qui est nouveau. "إعادة الفحص" demande d'abord aux
 * deux branches de renvoyer leurs bons et versements (demande "data", agent 1.0.10) ; un agent plus ancien ou une
 * branche hors ligne : la page le dit et analyse avec ce qui est arrive.
 */
class ReconcileHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $blida;

    private Branch $oran;

    private Partner $customer;

    private Partner $supplier;

    /** @var array<string, array<string, string>> en-tetes de l'agent de chaque branche */
    private array $agent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $this->blida = $this->register('Blida', '1.0.10');
        $this->oran = $this->register('Oran', '1.0.9');
        $this->customer = Partner::forceCreate(['branch_id' => $this->blida->id, 'kind' => 'customer', 'code' => 'ORAN', 'name' => 'Succursale Oran', 'gescomm_key' => 'ORAN']);
        $this->supplier = Partner::forceCreate(['branch_id' => $this->oran->id, 'kind' => 'supplier', 'code' => 'BLIDA', 'name' => 'Magasin Blida', 'gescomm_key' => 'BLIDA']);
        $this->bon($this->blida, 'sale', 'ORAN', 101, '2026-10-05', 4);
        $this->bon($this->blida, 'sale', 'ORAN', 102, '2026-10-06', 2);
        $this->bon($this->oran, 'purchase', 'BLIDA', 7, '2026-10-06', 2);
        Payment::forceCreate(['branch_id' => $this->blida->id, 'partner_kind' => 'customer', 'partner_code' => 'ORAN', 'number' => 1,
            'date' => '2026-10-10', 'type' => 'Espéce', 'amount' => 1000, 'gescomm_key' => 'p1', 'sync_group' => '2026-10-10']);
    }

    public function test_every_analysis_is_kept_and_the_next_one_says_what_was_fixed(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Karim']);
        $url = "/reconcile?ca={$this->customer->id}&sb={$this->supplier->id}";
        $first = $this->analyze($admin);

        // le bon 101 enfin saisi chez Oran, un versement de plus chez Oran, puis "إعادة الفحص"
        $this->bon($this->oran, 'purchase', 'BLIDA', 8, '2026-10-06', 4);
        Payment::forceCreate(['branch_id' => $this->oran->id, 'partner_kind' => 'supplier', 'partner_code' => 'BLIDA', 'number' => 9,
            'date' => '2026-10-20', 'type' => 'Espéce', 'amount' => 500, 'gescomm_key' => 'p9', 'sync_group' => '2026-10-20']);
        $second = $this->analyze($admin, synced: true);

        $this->assertSame(2, ReconciliationRun::count());
        $run = ReconciliationRun::find($second);
        $this->assertTrue($run->synced);
        $this->assertSame($first, $run->report['since']['previous_id']);
        $this->assertSame(['fixed' => 1, 'still' => 1, 'changed' => 0, 'new' => 1],
            collect($run->report['since'])->only(['fixed', 'still', 'changed', 'new'])->all());
        $this->assertSame('bon_missing_b', $run->report['fixed'][0]['type']);
        $this->assertSame(['pay_missing_a' => 'new', 'pay_missing_b' => 'still'],
            collect($run->report['problems'])->pluck('status', 'type')->sortKeys()->all());

        // la page : le dernier rapport, ce qui a change, l'historique ; un ancien rapport le dit
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee(__('reconcile.since.title', ['time' => ReconciliationRun::find($first)->created_at->format('d/m/Y H:i')]))
            ->assertSee(trans_choice('reconcile.since.fixed', 1, ['count' => 1]))
            ->assertSee(__('reconcile.status.still'))->assertSee(__('reconcile.status.new'))
            ->assertSee(__('reconcile.history.title', ['count' => 2]))->assertSee(__('reconcile.history.synced'))
            ->assertDontSee(__('reconcile.history.latest'));
        $this->actingAs($admin)->get($url.'&r='.$first)->assertOk()
            ->assertSee(__('reconcile.history.latest'))->assertDontSee(__('reconcile.since.fixed_list', ['count' => 1]));
        $this->actingAs($admin)->get($url.'&r=999999')->assertNotFound();

        // sans paire choisie : les dernieres paires analysees, pour y revenir
        $this->actingAs($admin)->get('/reconcile')->assertOk()->assertSee(__('reconcile.recent'))
            ->assertSee('Succursale Oran')->assertSee('Karim');
        $other = User::factory()->branchManager($this->blida)->withPermissions(['reconcile.use', 'sales.view', 'prices.sale', 'prices.buy'])->create();
        $this->actingAs($other)->get('/reconcile')->assertOk()->assertDontSee(__('reconcile.recent'));
    }

    public function test_a_problem_is_new_still_or_changed_and_the_ones_gone_are_fixed(): void
    {
        $bon = fn (int $id, int $n) => ['id' => $id, 'number' => $n, 'date' => '2026-10-01', 'amount' => 100.0, 'lines' => []];
        $previous = ['gap' => 300.0, 'more_problems' => 0, 'problems' => [
            ['type' => 'bon_missing_b', 'effect' => 100.0, 'date' => '2026-10-01', 'a' => [$bon(5, 105)], 'b' => []],
            ['type' => 'bon_missing_b', 'effect' => 100.0, 'date' => '2026-10-02', 'a' => [$bon(6, 106)], 'b' => []],
            ['type' => 'bon_missing_a', 'effect' => -50.0, 'date' => '2026-10-03', 'a' => [], 'b' => [$bon(3, 13)]],
        ]];
        $report = ['gap' => 150.0, 'problems' => [
            // le bon 105 saisi chez B, mais avec un autre montant : change, pas corrige
            ['type' => 'bon_amount', 'effect' => 20.0, 'date' => '2026-10-01', 'a' => [$bon(5, 105)], 'b' => [$bon(9, 19)]],
            ['type' => 'bon_missing_b', 'effect' => 100.0, 'date' => '2026-10-02', 'a' => [$bon(6, 106)], 'b' => []],
            ['type' => 'bon_missing_b', 'effect' => 30.0, 'date' => '2026-10-04', 'a' => [$bon(7, 107)], 'b' => []],
        ]];

        $r = Reconciliation::compare($report, $previous, ['previous_id' => 1]);

        $this->assertSame(['changed', 'still', 'new'], array_column($r['problems'], 'status'));
        $this->assertSame(['previous_id' => 1, 'gap' => 300.0, 'problems' => 3, 'fixed' => 1, 'still' => 1, 'changed' => 1, 'new' => 1], $r['since']);
        $this->assertSame([13], array_column(array_merge(...array_column($r['fixed'], 'b')), 'number'));
        $this->assertSame($report, Reconciliation::compare($report, null));
    }

    public function test_check_again_asks_the_branches_with_a_recent_agent_and_waits_for_their_answer(): void
    {
        $admin = User::factory()->admin()->create();
        $pair = ['ca' => $this->customer->id, 'sb' => $this->supplier->id];
        $this->blida->forceFill(['last_seen_at' => now()])->save();
        $this->oran->forceFill(['last_seen_at' => now()])->save();

        $res = $this->actingAs($admin)->postJson('/reconcile/sync', $pair)->assertOk();
        // un mois avant le premier bon de la paire, jusqu'a aujourd'hui
        $this->assertSame('2026-09-04', $res->json('from'));
        [$a, $b] = $res->json('branches');
        $this->assertSame('Blida', $a['branch']);
        $this->assertNotNull($a['job']);
        $this->assertNull($b['job']);
        $this->assertSame(__('reconcile.sync.old', ['branch' => 'Oran', 'version' => '1.0.10']), $b['note']);
        $job = RefreshJob::findOrFail($a['job']);
        $this->assertSame(['from' => '2026-09-04', 'to' => now()->toDateString(), 'sets' => ['sales', 'customer_payments', 'customers']], $job->params);

        // l'agent de Blida la recoit avec sa periode, renvoie ses donnees, puis repond
        $status = fn () => $this->actingAs($admin)->getJson('/reconcile/sync/'.$res->json('batch'))->assertOk()->json('jobs.0.status');
        $this->assertSame('pending', $status());
        $this->withHeaders($this->agent['Blida'])->getJson('/api/agent/pending')->assertOk()
            ->assertJson(['jobs' => [['id' => $job->id, 'type' => 'data', 'from' => '2026-09-04', 'sets' => ['sales', 'customer_payments', 'customers']]]]);
        $this->assertSame('taken', $status());
        $this->withHeaders($this->agent['Blida'])->postJson('/api/agent/result', ['job_id' => $job->id, 'type' => 'data', 'error' => null, 'read_at' => now()->toIso8601String()])->assertOk();
        $this->flushHeaders();
        $this->assertSame('done', $status());

        // hors ligne : pas de demande, la page le dit ; et un lot inconnu n'existe pas
        $this->blida->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->assertSame(__('reconcile.sync.offline', ['branch' => 'Blida']), $this->actingAs($admin)->postJson('/reconcile/sync', $pair)->json('branches.0.note'));
        $this->actingAs($admin)->getJson('/reconcile/sync/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->assertSame(1, RefreshJob::where('type', 'data')->count());
    }

    public function test_only_the_last_30_analyses_of_a_pair_are_kept(): void
    {
        for ($i = 0; $i < ReconciliationRun::KEEP + 2; $i++) {
            ReconciliationRun::forceCreate(['customer_id' => $this->customer->id, 'supplier_id' => $this->supplier->id,
                'branch_a_id' => $this->blida->id, 'branch_b_id' => $this->oran->id, 'gap' => $i, 'problems' => 0, 'report' => ['problems' => []]]);
        }
        ReconciliationRun::prune($this->customer->id, $this->supplier->id);

        $this->assertSame(ReconciliationRun::KEEP, ReconciliationRun::count());
        $this->assertSame(2.0, ReconciliationRun::orderBy('id')->first()->gap);
    }

    /** Toutes les etapes, comme la page ; renvoie le numero du rapport garde. */
    private function analyze(User $user, bool $synced = false): int
    {
        $step = 'load';
        $run = null;
        while ($step !== null) {
            $res = $this->actingAs($user)->postJson('/reconcile/analyze', ['ca' => $this->customer->id, 'sb' => $this->supplier->id, 'step' => $step, 'run' => $run, 'synced' => $synced ? 1 : 0])->assertOk();
            $run = $res->json('run') ?? $run;
            $step = $res->json('next');
        }

        return $run;
    }

    private function register(string $name, string $version): Branch
    {
        $headers = ['X-Agent-Version' => $version, 'Accept' => 'application/json'];
        $token = $this->withHeaders($headers)->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => $version,
        ])->assertCreated()->json('token');
        $this->flushHeaders();
        $this->agent[$name] = $headers + ['Authorization' => 'Bearer '.$token];

        return Branch::where('name', $name)->sole();
    }

    private function bon(Branch $branch, string $kind, string $partner, int $number, string $date, float $qty): void
    {
        $doc = Document::forceCreate(['branch_id' => $branch->id, 'kind' => $kind, 'number' => $number, 'date' => $date, 'partner_code' => $partner,
            'amount' => $qty * 550, 'gescomm_key' => $kind.$number, 'sync_group' => $date]);
        DocumentLine::forceCreate(['document_id' => $doc->id, 'branch_id' => $branch->id, 'kind' => $kind, 'date' => $date, 'position' => 1,
            'code_raw' => 'Z7', 'code_key' => BranchStock::keyFor('Z7'), 'qty' => $qty, 'price' => 550, 'total' => $qty * 550]);
    }
}
