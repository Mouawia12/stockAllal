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

    /** @return array<string, array{0: string, 1: bool, 2: bool}> version => [version, envoie les prix, comprend la demande "sync"] */
    public static function versions(): array
    {
        return [
            '1.0.0' => ['1.0.0', false, false],
            '1.0.1' => ['1.0.1', false, false],
            '1.0.2' => ['1.0.2', false, false],
            '1.0.3' => ['1.0.3', false, false],
            '1.0.4' => ['1.0.4', false, true],
            '1.0.5' => ['1.0.5', true, true],
            '1.0.6' => ['1.0.6', true, true],
        ];
    }

    public function test_the_current_agent_version_is_in_the_list(): void
    {
        $this->assertArrayHasKey(AgentDownloadController::version(), self::versions(),
            'new agent version: add its requests to AgentCompatibilityTest::versions()');
    }

    #[DataProvider('versions')]
    public function test_a_released_agent_still_syncs_and_answers_refresh(string $version, bool $prices, bool $syncJob): void
    {
        $agent = ['X-Agent-Version' => $version, 'Accept' => 'application/json'];
        $token = $this->withHeaders($agent)->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => 'Oran', 'hostname' => 'DESKTOP-9H7CGGI', 'agent_version' => $version,
        ])->assertCreated()->json('token');
        $branch = Branch::sole();
        $agent['Authorization'] = 'Bearer '.$token;
        $item = fn (float $stock) => ['code' => '820AC', 'designation' => 'BOULLON', 'stock' => $stock]
            + ($prices ? ['price' => 550.0, 'price_wholesale' => 400.0, 'price_buy' => 380.0] : []);

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
