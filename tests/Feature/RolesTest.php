<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Hierarchie des comptes (App\Support\Roles) : developer > owner > branch_manager > user.
 * Personne ne donne un role, une branche ou une permission qu'il n'a pas.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $oran;

    private Branch $setif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $this->setif = Branch::create(['name' => 'Setif', 'active' => true]);
    }

    private function account(string $username, string $role, ?Branch $branch, array $extra = []): array
    {
        return ['name' => ucfirst($username), 'username' => $username, 'password' => $username.'-2026', 'role' => $role,
            'branch_id' => $branch?->id] + $extra;
    }

    public function test_company_manager_manages_branch_accounts_of_his_branches_only(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach($this->oran);

        $this->actingAs($owner)->post('/users', $this->account('resp', 'branch_manager', $this->oran))->assertRedirect('/users');
        $this->actingAs($owner)->post('/users', $this->account('karim', 'user', $this->oran))->assertRedirect('/users');
        // pas de role au-dessus de branch_manager, pas d'autre branche que les siennes
        $this->actingAs($owner)->post('/users', $this->account('boss2', 'owner', null))->assertSessionHasErrors('role');
        $this->actingAs($owner)->post('/users', $this->account('dev2', 'developer', null))->assertSessionHasErrors('role');
        $this->actingAs($owner)->post('/users', $this->account('nadia', 'user', $this->setif))->assertSessionHasErrors('branch_id');
        $this->assertSame(['karim', 'resp'], User::whereIn('role', ['user', 'branch_manager'])->orderBy('username')->pluck('username')->all());

        // les comptes de Setif et le developpeur : ni sur sa page ni modifiables
        $dev = User::factory()->admin()->create(['name' => 'Le Dev']);
        $setifUser = User::factory()->inBranch($this->setif)->create(['name' => 'Ali Setif']);
        $this->actingAs($owner)->get('/users')->assertOk()->assertSee('Resp')->assertDontSee('Le Dev')->assertDontSee('Ali Setif');
        $this->actingAs($owner)->get("/users/{$setifUser->id}/edit")->assertForbidden();
        $this->actingAs($owner)->put("/users/{$dev->id}", $this->account('dev', 'user', $this->oran))->assertForbidden();
        $this->actingAs($owner)->delete("/users/{$setifUser->id}")->assertForbidden();
    }

    public function test_branch_manager_creates_users_of_his_branch_only(): void
    {
        $manager = User::factory()->branchManager($this->oran)->withPermissions(['stock.refresh', 'prices.sale'])->create();

        $this->actingAs($manager)->get('/users/create')->assertOk()
            ->assertSee('name="role" value="user"', false)->assertDontSee('name="role" value="branch_manager"', false)
            ->assertDontSee('Setif');
        $this->actingAs($manager)->post('/users', $this->account('karim', 'user', $this->oran))->assertRedirect('/users');
        $this->actingAs($manager)->post('/users', $this->account('resp2', 'branch_manager', $this->oran))->assertSessionHasErrors('role');
        $this->actingAs($manager)->post('/users', $this->account('ali', 'user', $this->setif))->assertSessionHasErrors('branch_id');

        $peer = User::factory()->branchManager($this->oran)->create();
        $this->actingAs($manager)->get("/users/{$peer->id}/edit")->assertForbidden();
        $this->actingAs(User::factory()->inBranch($this->oran)->create())->get('/users')->assertForbidden();
    }

    public function test_nobody_gives_a_permission_they_do_not_have(): void
    {
        $manager = User::factory()->branchManager($this->oran)->withPermissions(['stock.refresh', 'prices.sale'])->create();
        // le developpeur lui avait donne "سعر الشراء" : le directeur de branche ne l'a pas, il ne peut ni la donner ni l'enlever
        $karim = User::factory()->inBranch($this->oran)->withPermissions(['prices.buy'])->create(['username' => 'karim']);

        $this->actingAs($manager)->get("/users/{$karim->id}/edit")->assertOk()
            ->assertSee('value="prices.buy" checked disabled', false)
            ->assertSee('value="stock.refresh"', false);
        $this->actingAs($manager)->put("/users/{$karim->id}", [
            'name' => 'Karim', 'username' => 'karim', 'role' => 'user', 'branch_id' => $this->oran->id,
            'permissions' => ['stock.refresh', 'merge.manage'],
        ])->assertRedirect('/users');
        $this->assertSame(['stock.refresh', 'prices.buy'], $karim->fresh()->permissions, 'merge.manage refused, prices.buy kept');
    }

    public function test_a_disabled_account_cannot_sign_in_and_is_signed_out(): void
    {
        $admin = User::factory()->admin()->create();
        $karim = User::factory()->inBranch($this->oran)->create(['username' => 'karim', 'password' => 'karim-2026']);

        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/');
        $this->get('/stock')->assertOk();

        $karim->forceFill(['active' => false])->save();
        // chaque vraie requete relit le compte ; ici la meme application sert toutes les requetes du test
        Auth::forgetGuards();
        $this->get('/stock')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertSessionHasErrors('username');
        $this->assertGuest();

        // de nouveau actif depuis la page des comptes
        $this->actingAs($admin)->put("/users/{$karim->id}", ['name' => 'Karim', 'username' => 'karim', 'role' => 'user', 'branch_id' => $this->oran->id, 'active' => '1']);
        $this->assertTrue($karim->fresh()->active);
    }

    public function test_account_changes_are_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/users', $this->account('karim', 'user', $this->oran));
        $karim = User::where('username', 'karim')->firstOrFail();
        $this->actingAs($admin)->put("/users/{$karim->id}", ['name' => 'Karim', 'username' => 'karim', 'role' => 'branch_manager', 'branch_id' => $this->setif->id]);
        $this->actingAs($admin)->delete("/users/{$karim->id}");

        $log = ActivityLog::orderBy('id')->get();
        $this->assertSame(['user.created', 'user.updated', 'user.deleted'], $log->pluck('action')->all());
        $this->assertSame($admin->id, $log[1]->user_id);
        $this->assertSame(['user', 'branch_manager'], $log[1]->data['role']);
        $this->assertSame([$this->oran->id, $this->setif->id], $log[1]->data['branch_id']);
        $this->assertArrayNotHasKey('password', $log[0]->data['state'], 'never the password');
    }
}
