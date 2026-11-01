<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function registerBranch(string $name = 'Oran'): array
    {
        $code = InstallCode::generate(null);
        $res = $this->postJson('/api/agents/register', [
            'install_code' => $code,
            'branch_name' => $name,
            'hostname' => 'DESKTOP-TEST',
            'agent_version' => '1.0.0',
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    private function sync(string $token, array $items, array $extra = [])
    {
        return $this->withToken($token)->postJson('/api/agent/sync', $extra + ['items' => $items]);
    }

    public function test_install_code_is_single_use_and_case_insensitive(): void
    {
        $code = InstallCode::generate(null);
        $typed = strtolower(str_replace('-', ' ', $code));   // saisi a la main, en minuscules

        $res = $this->postJson('/api/agents/register', ['install_code' => $typed, 'branch_name' => 'الفرع الرئيسي'])
            ->assertCreated()
            ->assertJsonPath('branch_name', 'الفرع الرئيسي');
        $branch = Branch::findOrFail($res->json('branch_id'));
        $this->assertSame(hash('sha256', $res->json('token')), $branch->token_hash);

        $this->postJson('/api/agents/register', ['install_code' => $code, 'branch_name' => 'Autre'])->assertStatus(422);
        $this->assertSame(1, Branch::count());
    }

    public function test_expired_install_code_is_refused(): void
    {
        $code = InstallCode::generate(null);
        $this->travel(25)->hours();

        $this->postJson('/api/agents/register', ['install_code' => $code, 'branch_name' => 'Oran'])->assertStatus(422);
    }

    public function test_agent_routes_require_an_active_branch_token(): void
    {
        [$token, $branch] = $this->registerBranch();

        $this->getJson('/api/agent/ping')->assertUnauthorized();
        $this->withToken('faux')->getJson('/api/agent/ping')->assertUnauthorized();
        $this->withToken($token)->withHeader('X-Agent-Version', '1.2.3')->getJson('/api/agent/ping')
            ->assertOk()->assertJsonPath('branch_name', 'Oran');
        $this->assertSame('1.2.3', $branch->fresh()->agent_version);

        $branch->update(['active' => false]);
        $this->withToken($token)->getJson('/api/agent/ping')->assertUnauthorized();
    }

    public function test_sync_replaces_the_branch_stock(): void
    {
        [$token, $branch] = $this->registerBranch();
        [$otherToken] = $this->registerBranch('Setif');

        $this->sync($otherToken, [['code' => 'A1', 'designation' => 'Autre branche', 'stock' => 9]])->assertOk();
        $this->sync($token, [
            ['code' => '820AC', 'designation' => 'BOULLON ACIER', 'stock' => 164.11],
            ['code' => 'R A  ', 'designation' => 'RIVETEUSE', 'stock' => 0],
            ['code' => 'TP¨PA', 'designation' => 'TESTRA', 'stock' => -2.5],
        ], ['db_file_mtime' => '2026-11-01T14:42:20+01:00', 'hostname' => 'DESKTOP-9H7CGGI'])
            ->assertOk()->assertJsonPath('count', 3);

        $this->assertSame(['820AC', 'R A', 'TP¨PA'], BranchStock::where('branch_id', $branch->id)->orderBy('code_raw')->pluck('code_raw')->all());
        $branch->refresh();
        $this->assertSame(3, $branch->items_count);
        $this->assertSame('2026-11-01 14:42', $branch->db_file_mtime->format('Y-m-d H:i'));

        $this->travel(1)->minutes();
        $this->sync($token, [
            ['code' => '820AC', 'designation' => 'BOULLON ACIER', 'stock' => 163.11],
            ['code' => 'NEW1', 'designation' => 'Nouveau', 'stock' => 1],
        ])->assertOk();

        $rows = BranchStock::where('branch_id', $branch->id)->pluck('stock', 'code_raw');
        $this->assertEquals(['820AC' => 163.11, 'NEW1' => 1.0], $rows->sortKeys()->all());
        $this->assertSame(1, BranchStock::where('code_raw', 'A1')->count(), "le stock d'une autre branche ne bouge pas");

        // liste vide = lecture ratee cote agent : refusee, rien n'est efface
        $this->sync($token, [])->assertStatus(422);
        $this->assertSame(2, BranchStock::where('branch_id', $branch->id)->count());
    }

    public function test_refresh_round_trip_through_the_agents(): void
    {
        [$oranToken, $oran] = $this->registerBranch('Oran');
        [$setifToken, $setif] = $this->registerBranch('Setif');
        $this->sync($oranToken, [['code' => '820AC', 'designation' => 'BOULLON', 'stock' => 164.11]]);
        $this->sync($setifToken, [['code' => '820ac', 'designation' => 'BOULON', 'stock' => 40]]);

        $user = User::factory()->create();
        $batch = $this->actingAs($user)->postJson('/refresh', ['code' => ' 820ac '])->assertOk()->json('batch');
        $this->assertSame(2, RefreshJob::where('batch', $batch)->where('code_key', '820AC')->count());

        $jobs = $this->withToken($oranToken)->getJson('/api/agent/pending')->assertOk()->json('jobs');
        $this->assertCount(1, $jobs);
        $this->assertSame('820AC', $jobs[0]['code']);
        $this->withToken($oranToken)->getJson('/api/agent/pending')->assertJsonCount(0, 'jobs');

        $this->withToken($oranToken)->postJson('/api/agent/result', [
            'job_id' => $jobs[0]['id'], 'code' => '820AC', 'found' => true, 'error' => null,
            'items' => [['code' => '820AC', 'designation' => 'BOULLON', 'stock' => 163.11]],
        ])->assertOk();

        $setifJob = $this->withToken($setifToken)->getJson('/api/agent/pending')->json('jobs.0.id');
        // une branche ne peut pas repondre a la demande d'une autre
        $this->withToken($oranToken)->postJson('/api/agent/result', ['job_id' => $setifJob, 'found' => false])->assertNotFound();
        $this->withToken($setifToken)->postJson('/api/agent/result', ['job_id' => $setifJob, 'found' => false, 'items' => []])->assertOk();

        $product = $this->actingAs($user)->getJson('/product?code=820AC&batch='.$batch)->assertOk()->json();
        $this->assertTrue($product['done']);
        $byBranch = collect($product['branches'])->keyBy('branch');
        $this->assertSame('done', $byBranch['Oran']['status']);
        $this->assertEquals(163.11, $byBranch['Oran']['rows'][0]['stock']);
        $this->assertSame('not_found', $byBranch['Setif']['status']);
        $this->assertSame([], $byBranch['Setif']['rows'], "l'article disparu de Setif est retire");
    }

    public function test_sync_now_button_round_trip(): void
    {
        [$token, $branch] = $this->registerBranch();
        $user = User::factory()->admin()->create();

        $jobId = $this->actingAs($user)->postJson("/branches/{$branch->id}/sync")->assertOk()->json('job');
        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$jobId}")->assertJsonPath('status', 'pending');

        $jobs = $this->withToken($token)->getJson('/api/agent/pending')->json('jobs');
        $this->assertSame([['id' => $jobId, 'type' => 'sync', 'code' => null]], $jobs);
        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$jobId}")->assertJsonPath('status', 'taken');

        // l'agent envoie le stock, puis confirme la demande
        $this->sync($token, [['code' => '820AC', 'designation' => 'BOULLON', 'stock' => 164.11]])->assertOk();
        $this->withToken($token)->postJson('/api/agent/result', ['job_id' => $jobId, 'type' => 'sync', 'count' => 1, 'error' => null])->assertOk();

        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$jobId}")
            ->assertJsonPath('status', 'done')->assertJsonPath('items_count', 1);
        $this->assertSame(1, BranchStock::where('branch_id', $branch->id)->count(), 'une confirmation de synchro ne touche pas au stock');
    }

    public function test_sync_now_reports_agent_errors_and_timeouts(): void
    {
        [$token, $branch] = $this->registerBranch();
        [, $other] = $this->registerBranch('Setif');
        $user = User::factory()->admin()->create();

        $failed = $this->actingAs($user)->postJson("/branches/{$branch->id}/sync")->json('job');
        $this->withToken($token)->getJson('/api/agent/pending');
        $this->withToken($token)->postJson('/api/agent/result', ['job_id' => $failed, 'type' => 'sync', 'error' => 'sync_failed'])->assertOk();
        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$failed}")
            ->assertJsonPath('status', 'error')->assertJsonPath('error', 'sync_failed');

        $silent = $this->actingAs($user)->postJson("/branches/{$branch->id}/sync")->json('job');
        $this->travel(RefreshJob::TIMEOUT_SECONDS + 5)->seconds();
        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$silent}")->assertJsonPath('status', 'pending');
        $this->travel(RefreshJob::SYNC_TIMEOUT_SECONDS)->seconds();
        $this->actingAs($user)->getJson("/branches/{$branch->id}/sync/{$silent}")->assertJsonPath('status', 'timeout');

        // la demande d'une branche ne se lit pas sous une autre
        $this->actingAs($user)->getJson("/branches/{$other->id}/sync/{$silent}")->assertNotFound();

        $branch->update(['active' => false]);
        $this->actingAs($user)->postJson("/branches/{$branch->id}/sync")->assertStatus(422);
    }

    public function test_unanswered_refresh_times_out(): void
    {
        [$token] = $this->registerBranch();
        $this->sync($token, [['code' => '820AC', 'designation' => 'BOULLON', 'stock' => 5]]);
        $user = User::factory()->create();
        $batch = $this->actingAs($user)->postJson('/refresh', ['code' => '820AC'])->json('batch');

        $this->actingAs($user)->getJson('/product?code=820AC&batch='.$batch)
            ->assertJsonPath('done', false)->assertJsonPath('branches.0.status', 'pending');

        $this->travel(RefreshJob::TIMEOUT_SECONDS + 1)->seconds();
        $this->actingAs($user)->getJson('/product?code=820AC&batch='.$batch)
            ->assertJsonPath('done', true)->assertJsonPath('branches.0.status', 'timeout');

        // trop vieille : un agent qui revient en ligne ne la recoit plus
        $this->travel(RefreshJob::PICKUP_MINUTES)->minutes();
        $this->withToken($token)->getJson('/api/agent/pending')->assertJsonCount(0, 'jobs');
    }
}
