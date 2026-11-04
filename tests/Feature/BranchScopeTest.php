<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentDownloadController;
use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ce que chaque compte voit des branches : sa branche + celles que branch_access lui ouvre (docs/roadmap.md). */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} token et branche, comme le fait GescomAgent-Setup.exe */
    private function register(string $name, ?User $by = null, ?Branch $for = null): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate($by?->id, $for?->id), 'branch_name' => $name,
            'hostname' => 'PC-'.$name, 'agent_version' => AgentDownloadController::version(),
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    private function stock(Branch $branch, float $qty): void
    {
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => '820AC', 'code_key' => '820AC', 'designation' => 'BOULLON',
            'stock' => $qty, 'seen_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_branch_user_sees_his_branch_and_the_ones_opened_to_it(): void
    {
        [, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida');
        $this->stock($oran, 10);
        $this->stock($setif, 20);
        $this->stock($blida, 40);
        $karim = User::factory()->inBranch($oran)->create();

        // une nouvelle branche voit toutes les autres, comme avant : 10 + 20 + 40
        $this->actingAs($karim)->get('/stock?q=820AC')->assertSee('70,00');
        // Oran ne voit plus Blida
        BranchAccess::set($oran->id, 'stock', [$setif->id], [$oran->id, $setif->id, $blida->id]);
        $this->assertSame([$oran->id, $setif->id], $karim->stockBranchIds());

        $this->actingAs($karim)->get('/stock?q=820AC')->assertSee('30,00')->assertDontSee('70,00');
        $product = $this->actingAs($karim)->getJson('/product?code=820AC')->assertOk()->json('branches');
        $this->assertEqualsCanonicalizing(['Oran', 'Setif'], array_column($product, 'branch'));

        $batch = $this->actingAs($karim)->postJson('/refresh', ['code' => '820AC'])->json('batch');
        $this->assertEqualsCanonicalizing([$oran->id, $setif->id], RefreshJob::where('batch', $batch)->pluck('branch_id')->all());

        $this->actingAs($karim)->get('/branches')->assertOk()->assertSee('Setif')->assertDontSee('Blida');
        $this->actingAs($karim)->postJson("/branches/{$blida->id}/sync")->assertNotFound();
        // Setif, elle, voit toujours Blida : chaque branche a ses propres reglages
        $this->assertContains($blida->id, User::factory()->inBranch($setif)->create()->stockBranchIds());
    }

    public function test_developer_sees_everything_and_an_account_without_branch_sees_everything_as_before(): void
    {
        [, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        BranchAccess::set($oran->id, 'stock', [], [$oran->id, $setif->id]);

        $this->assertNull(User::factory()->admin()->create()->stockBranchIds());
        // employe d'avant les roles, pas encore rattache : rien ne change pour lui le jour de la mise a jour
        $this->assertNull(User::factory()->create()->stockBranchIds());
        $this->assertSame([$oran->id], User::factory()->inBranch($oran)->create()->stockBranchIds());
    }

    public function test_company_manager_sees_his_branches_and_sets_who_sees_whom_among_them(): void
    {
        [, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida');
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$oran->id, $setif->id]);
        $this->assertEqualsCanonicalizing([$oran->id, $setif->id], $owner->stockBranchIds());

        $this->actingAs($owner)->get('/branches')->assertOk()->assertSee('من يرى مخزون من')->assertDontSee('Blida');
        // Oran ne voit plus Setif ; Blida, qui n'est pas a lui, reste comme elle etait
        $this->actingAs($owner)->put("/branches/{$oran->id}/access", ['visible' => []])->assertRedirect();
        $this->assertSame([$blida->id], BranchAccess::visibleFrom($oran->id, 'stock'));
        $this->actingAs($owner)->put("/branches/{$oran->id}/access", ['visible' => [$blida->id]])->assertSessionHasErrors('visible.0');
        $this->actingAs($owner)->put("/branches/{$blida->id}/access", ['visible' => []])->assertNotFound();

        $this->actingAs($owner)->put("/branches/{$oran->id}/access", ['visible' => [$setif->id]]);
        $this->assertEqualsCanonicalizing([$setif->id, $blida->id], BranchAccess::visibleFrom($oran->id, 'stock'));
        $this->assertSame(2, DB::table('activity_log')->where('action', 'branch.access')->count());

        // le directeur de branche ne regle pas les acces
        $this->actingAs(User::factory()->branchManager($oran)->create())->put("/branches/{$oran->id}/access", ['visible' => []])->assertForbidden();
    }

    public function test_a_branch_installed_by_the_company_manager_becomes_one_of_his(): void
    {
        $owner = User::factory()->owner()->create();
        [, $oran] = $this->register('Oran', $owner);

        $this->assertSame([$oran->id], $owner->branches()->pluck('branches.id')->all());
    }

    public function test_a_reinstall_code_keeps_the_same_branch(): void
    {
        $admin = User::factory()->admin()->create();
        [$oldToken, $oran] = $this->register('Oran');
        $this->stock($oran, 10);
        $karim = User::factory()->inBranch($oran)->create();

        $this->actingAs($admin)->post('/branches/install-code', ['branch_id' => $oran->id])
            ->assertSessionHas('install_code')->assertSessionHas('install_code_branch', 'Oran');
        $this->assertSame($oran->id, InstallCode::latest('id')->first()->for_branch_id);

        // nouveau PC : meme branche, ses comptes et son stock ; le nom envoye par l'installateur ne la renomme pas
        [$newToken, $again] = $this->register('Oran (nouveau PC)', null, $oran);
        $this->assertSame($oran->id, $again->id);
        $this->assertSame(['Oran', 'PC-Oran (nouveau PC)'], [$again->name, $again->hostname]);
        $this->assertSame(1, Branch::count());
        $this->assertSame($oran->id, $karim->fresh()->branch_id);
        $this->assertSame(1, BranchStock::where('branch_id', $oran->id)->count());

        $this->withToken($newToken)->getJson('/api/agent/ping')->assertOk();
        $this->withToken($oldToken)->getJson('/api/agent/ping')->assertUnauthorized();
    }

    /** Ventes : sa branche seulement, meme quand le stock d'une autre lui est ouvert (decision du 04/11/2026). */
    public function test_a_user_of_a_never_sees_the_sales_of_b(): void
    {
        [$oranToken, $oran] = $this->register('Oran');
        [$setifToken, $setif] = $this->register('Setif');
        $oran->forceFill(['agent_version' => '1.0.7'])->save();
        $setif->forceFill(['agent_version' => '1.0.7'])->save();
        $this->stock($oran, 10);
        $this->stock($setif, 20);
        foreach ([[$oranToken, 40501, 3], [$setifToken, 70777, 9]] as [$token, $number, $qty]) {
            $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [now()->toDateString() => [
                ['key' => (string) $number, 'digest' => $number, 'number' => $number, 'lines' => [['code' => '820AC', 'qty' => $qty, 'total' => $qty * 100]]],
            ]]])->assertOk();
        }
        $karim = User::factory()->inBranch($oran)->withPermissions(['prices.sale', 'sales.view'])->create();

        // il voit le stock de Setif (nouvelle branche = ouverte a toutes), pas ses ventes
        $this->assertContains($setif->id, $karim->stockBranchIds());
        $this->assertSame([$oran->id], $karim->salesBranchIds());
        $html = $this->actingAs($karim)->get('/stock/item?code=820AC')->assertOk()->assertSee('Setif')->getContent();
        $this->assertStringContainsString('40501', $html);
        $this->assertStringNotContainsString('70777', $html);
        $this->assertStringNotContainsString('9,00', $html);

        // une nouvelle branche n'ouvre pas ses ventes ; on peut les ouvrir une par une
        $this->assertSame(0, DB::table('branch_access')->where('module', 'sales')->count());
        BranchAccess::set($oran->id, 'sales', [$setif->id], [$oran->id, $setif->id]);
        $this->assertStringContainsString('70777', $this->actingAs($karim)->get('/stock/item?code=820AC')->getContent());

        // un compte sans branche (d'avant les roles) voit le stock de tout le monde, les ventes de personne
        $old = User::factory()->withPermissions(['sales.view'])->create();
        $this->assertNull($old->stockBranchIds());
        $this->assertSame([], $old->salesBranchIds());
        $this->assertStringNotContainsString('40501', $this->actingAs($old)->get('/stock/item?code=820AC')->getContent());
    }
}
