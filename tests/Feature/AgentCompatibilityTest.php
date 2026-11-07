<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentDownloadController;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les branches mettent leur agent a jour a la main, quand on le decide : chaque version sortie doit continuer a
 * synchroniser et a repondre a "تحديث الآن" apres chaque mise a jour du serveur (CLAUDE.md, "Old agents keep working").
 * Les requetes sont celles que chaque version envoie vraiment (agent.ps1 de son commit).
 * Nouvelle version d'agent = une ligne de plus dans versions() ; ne jamais en retirer une.
 */
class AgentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
     *                                                                             version => [version, envoie les prix, comprend la demande "sync", envoie ventes / achats / clients (digest + records), envoie la caisse]
     */
    public static function versions(): array
    {
        return [
            '1.0.0' => ['1.0.0', false, false, false, false],
            '1.0.1' => ['1.0.1', false, false, false, false],
            '1.0.2' => ['1.0.2', false, false, false, false],
            '1.0.3' => ['1.0.3', false, false, false, false],
            '1.0.4' => ['1.0.4', false, true, false, false],
            '1.0.5' => ['1.0.5', true, true, false, false],
            '1.0.6' => ['1.0.6', true, true, false, false],
            '1.0.7' => ['1.0.7', true, true, true, false],
            '1.0.8' => ['1.0.8', true, true, true, true],
            // memes requetes que 1.0.8 : seule la tache planifiee change (retour de veille, surveillance sans fin)
            '1.0.9' => ['1.0.9', true, true, true, true],
            // memes requetes que 1.0.9, plus la demande "data" ("إعادة الفحص", ReconcileHistoryTest)
            '1.0.10' => ['1.0.10', true, true, true, true],
        ];
    }

    public function test_the_current_agent_version_is_in_the_list(): void
    {
        $this->assertArrayHasKey(AgentDownloadController::version(), self::versions(),
            'new agent version: add its requests to AgentCompatibilityTest::versions()');
    }

    #[DataProvider('versions')]
    public function test_a_released_agent_still_syncs_and_answers_refresh(string $version, bool $prices, bool $syncJob, bool $documents, bool $cash): void
    {
        $agent = ['X-Agent-Version' => $version, 'Accept' => 'application/json'];
        $token = $this->withHeaders($agent)->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => 'Oran', 'hostname' => 'DESKTOP-9H7CGGI', 'agent_version' => $version,
        ])->assertCreated()->json('token');
        $branch = Branch::sole();
        $agent['Authorization'] = 'Bearer '.$token;
        $item = fn (float $stock) => ['code' => '820AC', 'designation' => 'BOULLON', 'stock' => $stock]
            + ($prices ? ['price' => 550.0, 'price_wholesale' => 400.0, 'price_buy' => 380.0] : [])
            + ($documents ? ['stock_alert' => 10.0, 'stock_max' => 500.0, 'family' => 'VIS', 'unit' => 'PCS'] : []);

        // synchro toutes les 5 min
        $this->withHeaders($agent)->getJson('/api/agent/ping')->assertOk();
        $this->withHeaders($agent)->postJson('/api/agent/sync', [
            'generated_at' => now()->toIso8601String(), 'agent_version' => $version, 'hostname' => 'DESKTOP-9H7CGGI',
            'db_file_mtime' => now()->subMinutes(3)->toIso8601String(), 'items' => [$item(164.11)],
        ])->assertOk()->assertJson(['count' => 1]);
        $row = BranchStock::sole();
        $this->assertEquals(164.11, $row->stock);
        $this->assertEquals($prices ? 550 : null, $row->price, 'without prices (before 1.0.5) = unknown, not 0');
        $this->assertSame($version, $branch->fresh()->agent_version);
        $this->assertEquals($documents ? 10 : null, $row->stock_alert, 'without levels (before 1.0.7) = unknown');

        // ventes et clients : 1.0.7 envoie les empreintes puis les jours demandes ; avant, la page dit de mettre l'agent a jour
        $seller = User::factory()->inBranch($branch)->withPermissions(['prices.sale', 'sales.view'])->create();
        if ($documents) {
            $day = now()->toDateString();
            $bon = ['key' => '1475', 'digest' => 81985315468, 'number' => 1475, 'time' => '09:18:33.0000', 'partner' => 'C001',
                'amount' => 1100.0, 'lines' => [['code' => '820AC', 'qty' => 2.0, 'price' => 550.0, 'total' => 1100.0, 'cost' => 380.0, 'position' => 1]]];
            $this->withHeaders($agent)->postJson('/api/agent/digest', ['dataset' => 'sales', 'from' => now()->subDays(6)->toDateString(), 'to' => $day,
                'groups' => [$day => '1:81985315468']])->assertOk()->assertJson(['send' => [$day]]);
            $this->withHeaders($agent)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [$day => [$bon]]])->assertOk();
            $this->withHeaders($agent)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p12' => [['key' => 'C001', 'digest' => 5, 'name' => 'Benali']]]])->assertOk();
            $this->actingAs($seller)->get('/stock/item?code=820AC')->assertOk()->assertSee('1475')->assertSee('Benali');

            // la caisse : 1.0.8 envoie ses entrees et ses sorties ; avant, "لوحة القيادة" dit de mettre l'agent a jour
            $cashier = User::factory()->inBranch($branch)->withPermissions(['sales.view', 'cash.view'])->create();
            if ($cash) {
                $this->withHeaders($agent)->postJson('/api/agent/records', ['dataset' => 'cash_in', 'groups' => [$day => [
                    ['key' => $day.'|1252', 'digest' => 7, 'number' => '1252', 'time' => '10:47:00.0000', 'amount' => '66000.00', 'label' => 'Client: Benali', 'payment_number' => '1536', 'counter' => '0'],
                ]]])->assertOk();
                $this->withHeaders($agent)->postJson('/api/agent/records', ['dataset' => 'cash_out', 'groups' => [$day => [
                    ['key' => $day.'|189', 'digest' => 8, 'number' => '189', 'time' => '10:51:46.0000', 'amount' => '20000.50', 'label' => 'Fourn: TPL', 'expense' => 'N'],
                ]]])->assertOk();
                $this->actingAs($cashier)->get('/dashboard')->assertOk()->assertSee('45 999,50');
            } else {
                $this->actingAs($cashier)->get('/dashboard')->assertOk()
                    ->assertSee(__('dashboard.cash.none', ['names' => 'Oran', 'version' => Branch::AGENT_FEATURES['cash']]));
            }
        } else {
            $this->actingAs($seller)->get('/stock/item?code=820AC')->assertOk()
                ->assertSee(__('stock.activity.outdated', ['names' => 'Oran']));
        }

        // "تحديث الآن"
        $user = User::factory()->create();
        $batch = $this->actingAs($user)->postJson('/refresh', ['code' => '820AC'])->assertOk()->json('batch');
        $job = $this->withHeaders($agent)->getJson('/api/agent/pending')->assertOk()->json('jobs.0');
        $this->assertSame(['type' => 'code', 'code' => '820AC'], ['type' => $job['type'], 'code' => $job['code']]);
        $this->withHeaders($agent)->postJson('/api/agent/result', [
            'job_id' => $job['id'], 'code' => '820AC', 'found' => true, 'items' => [$item(163.0)], 'error' => null, 'read_at' => now()->toIso8601String(),
        ])->assertOk();
        $this->actingAs($user)->getJson('/product?code=820AC&batch='.$batch)
            ->assertJsonPath('branches.0.status', 'done')->assertJsonPath('branches.0.rows.0.stock', 163);

        // "مزامنة الآن" : jamais d'erreur a cause de la version. Un agent qui la connait recoit la demande ;
        // un plus ancien (il la lirait comme un code vide : invalid_code) ne la recoit pas, sa synchro automatique y repond
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/branches')->assertOk()->assertSee('data-sync=', false);
        $this->travel(1)->seconds();
        $sync = $this->actingAs($admin)->postJson("/branches/{$branch->id}/sync")->assertOk();
        $status = fn () => $this->actingAs($admin)->getJson("/branches/{$branch->id}/sync/{$sync->json('job')}")->json('status');
        $jobs = $this->withHeaders($agent)->getJson('/api/agent/pending')->json('jobs');
        if ($syncJob) {
            $this->assertSame(['pending', RefreshJob::SYNC_TIMEOUT_SECONDS], [$sync->json('status'), $sync->json('wait_seconds')]);
            $this->assertSame('sync', $jobs[0]['type']);
        } else {
            $this->assertSame(['waiting', RefreshJob::WAIT_SYNC_SECONDS], [$sync->json('status'), $sync->json('wait_seconds')]);
            $this->assertSame([], $jobs, 'never sent to an agent that does not know it');
            $this->assertSame('waiting', $status());

            // sa synchro automatique arrive 4 min plus tard : succes
            $this->travel(4)->minutes();
            $this->withHeaders($agent)->postJson('/api/agent/sync', ['agent_version' => $version, 'items' => [$item(162.0)]])->assertOk();
            $this->assertSame('done', $status());

            // agent arrete : pas de synchro automatique, "pas de reponse" apres WAIT_SYNC_SECONDS
            $this->travel(1)->seconds();
            $silent = $this->actingAs($admin)->postJson("/branches/{$branch->id}/sync")->json('job');
            $this->travel(RefreshJob::WAIT_SYNC_SECONDS - 60)->seconds();
            $this->assertSame('waiting', $this->actingAs($admin)->getJson("/branches/{$branch->id}/sync/{$silent}")->json('status'));
            $this->travel(61)->seconds();
            $this->assertSame('timeout', $this->actingAs($admin)->getJson("/branches/{$branch->id}/sync/{$silent}")->json('status'));
        }
    }
}
