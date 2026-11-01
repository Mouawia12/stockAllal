<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synchro legere, appels rejouables sans doublon, pages d'erreur et page expiree. */
class ResilienceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function registerBranch(string $name = 'Oran'): array
    {
        $res = $this->postJson('/api/agents/register', ['install_code' => InstallCode::generate(null), 'branch_name' => $name])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    public function test_a_sync_only_writes_the_rows_that_changed(): void
    {
        [$token, $branch] = $this->registerBranch();
        $items = [
            ['code' => '820AC', 'designation' => 'BOULON', 'stock' => 164.11, 'price' => 45],
            ['code' => '612Z', 'designation' => 'VIS', 'stock' => 3],
            ['code' => 'OLD1', 'designation' => 'ANCIEN', 'stock' => 1],
        ];
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => $items])
            ->assertOk()->assertJson(['count' => 3, 'changed' => 3, 'removed' => 0]);
        $firstWrite = BranchStock::where('code_raw', '612Z')->value('updated_at');

        // 5 minutes plus tard, rien n'a change : aucune ligne reecrite
        $this->travel(5)->minutes();
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => $items])
            ->assertOk()->assertJson(['count' => 3, 'changed' => 0, 'removed' => 0]);
        $this->assertEquals($firstWrite, BranchStock::where('code_raw', '612Z')->value('updated_at'));
        $this->assertSame(now()->format('Y-m-d H:i'), $branch->fresh()->last_sync_at->format('Y-m-d H:i'));

        // une quantite change (au centime pres), un article disparait
        $this->travel(5)->minutes();
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [
            ['code' => '820AC', 'designation' => 'BOULON', 'stock' => '164.110', 'price' => '45.00'],
            ['code' => '612Z', 'designation' => 'VIS', 'stock' => 2],
        ]])->assertOk()->assertJson(['count' => 2, 'changed' => 1, 'removed' => 1]);
        $this->assertEquals(2, BranchStock::where('code_raw', '612Z')->value('stock'));
        $this->assertNull(BranchStock::where('code_raw', 'OLD1')->first());
        $this->assertSame(2, $branch->fresh()->items_count);
    }

    public function test_an_unchanged_value_shows_the_last_sync_as_its_freshness(): void
    {
        [$token, $branch] = $this->registerBranch();
        $items = [['code' => '820AC', 'designation' => 'BOULON', 'stock' => 10]];
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => $items]);

        $this->travel(3)->days();
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => $items])->assertJson(['changed' => 0]);

        $data = $this->actingAs(User::factory()->create())->getJson('/product?code=820AC')->json();
        $this->assertSame($branch->fresh()->last_sync_at->diffForHumans(), $data['branches'][0]['rows'][0]['updated'], 'confirmee a la derniere synchro, pas il y a 3 jours');
    }

    public function test_agent_polls_record_the_heartbeat_every_30_seconds_only(): void
    {
        [$token, $branch] = $this->registerBranch();
        $seen = $branch->fresh()->last_seen_at;

        $this->travel(10)->seconds();
        $this->withToken($token)->getJson('/api/agent/pending')->assertOk();
        $this->assertEquals($seen, $branch->fresh()->last_seen_at);

        $this->travel(25)->seconds();
        $this->withToken($token)->getJson('/api/agent/pending')->assertOk();
        $this->assertTrue($branch->fresh()->last_seen_at->gt($seen));
        $this->assertTrue($branch->fresh()->isOnline());
    }

    public function test_a_replayed_refresh_or_sync_request_does_not_create_jobs_twice(): void
    {
        [, $oran] = $this->registerBranch('Oran');
        $this->registerBranch('Setif');
        $admin = User::factory()->admin()->create();

        $batch = (string) Str::uuid();
        $this->actingAs($admin)->postJson('/refresh', ['code' => '820AC', 'batch' => $batch])->assertOk()->assertJson(['batch' => $batch]);
        $this->actingAs($admin)->postJson('/refresh', ['code' => '820AC', 'batch' => $batch])->assertOk()->assertJson(['batch' => $batch]);
        $this->assertSame(2, RefreshJob::where('batch', $batch)->count(), 'une demande par branche, pas deux');
        $this->actingAs($admin)->postJson('/refresh', ['code' => '820AC', 'batch' => 'pas-un-uuid'])->assertUnprocessable();

        $syncBatch = (string) Str::uuid();
        $job = $this->actingAs($admin)->postJson("/branches/{$oran->id}/sync", ['batch' => $syncBatch])->assertOk()->json('job');
        $this->actingAs($admin)->postJson("/branches/{$oran->id}/sync", ['batch' => $syncBatch])->assertOk()->assertJson(['job' => $job]);
        $this->assertSame(1, RefreshJob::where('type', 'sync')->count());
    }

    public function test_an_expired_page_goes_back_to_its_form_with_a_message(): void
    {
        // les tests desactivent la verification CSRF : on simule son echec
        Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);

        $this->from('/login')->post('/_expired', ['username' => 'karim', 'password' => 'secret-123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('session')
            ->assertSessionHasInput('username', 'karim')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_pages_register_the_reconnect_service_worker(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString("'/offline.html'", file_get_contents(public_path('sw.js')));

        $this->get('/login')->assertOk()->assertSee('serviceWorker.register', false);
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('serviceWorker.register', false);
    }

    public function test_maintenance_and_server_errors_show_friendly_pages(): void
    {
        Route::get('/_down', fn () => abort(503));
        Route::get('/_boom', fn () => abort(500));

        $this->get('/_down')->assertStatus(503)->assertSee('تحديث قصير للنظام')->assertSee('location.reload()', false);
        $this->get('/_boom')->assertStatus(500)->assertSee('حدث خطأ غير متوقع');
        $this->getJson('/_down')->assertStatus(503)->assertJsonStructure(['message']);
    }
}
