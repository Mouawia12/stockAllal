<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_workers_see_stock_but_not_admin_pages(): void
    {
        $worker = User::factory()->create();
        $branch = Branch::create(['name' => 'Oran', 'active' => true]);

        $this->actingAs($worker)->get('/')->assertOk()->assertDontSee('المستخدمون')->assertDontSee(route('branches.index'));
        $this->actingAs($worker)->getJson('/product?code=820AC')->assertOk();
        $this->actingAs($worker)->postJson('/refresh', ['code' => '820AC'])->assertOk();
        $this->actingAs($worker)->get('/account')->assertOk();

        $this->actingAs($worker)->get('/branches')->assertForbidden()->assertSee('هذه الصفحة للمدير فقط');
        $this->actingAs($worker)->get('/users')->assertForbidden();
        $this->actingAs($worker)->get('/agent/download')->assertForbidden();
        $this->actingAs($worker)->post('/branches/install-code')->assertForbidden();
        $this->actingAs($worker)->postJson("/branches/{$branch->id}/sync")->assertForbidden();
        $this->actingAs($worker)->post('/users', ['name' => 'x', 'username' => 'x', 'password' => 'xxxxxxxx', 'is_admin' => 1])->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_admin_creates_a_worker_who_can_log_in(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('حساب جديد');
        $this->actingAs($admin)->post('/users', ['name' => 'Karim', 'username' => 'karim', 'password' => 'karim-2026'])
            ->assertRedirect('/users')->assertSessionHas('status');

        $karim = User::where('username', 'karim')->firstOrFail();
        $this->assertFalse($karim->is_admin);

        $this->post('/logout');
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/');
        $this->get('/branches')->assertForbidden();
    }

    public function test_ticking_admin_promotes_the_user_after_save(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->create(['username' => 'samir', 'password' => 'samir-2026']);

        $this->actingAs($admin)->put("/users/{$worker->id}", ['name' => 'Samir', 'username' => 'samir', 'is_admin' => '1'])
            ->assertRedirect('/users');
        $worker->refresh();
        $this->assertTrue($worker->is_admin);
        $this->assertTrue(\Hash::check('samir-2026', $worker->password), 'mot de passe vide = inchange');
        $this->actingAs($worker)->get('/users')->assertOk();

        // case decochee = retour a employe ; nouveau mot de passe pris en compte
        $this->actingAs($admin)->put("/users/{$worker->id}", ['name' => 'Samir', 'username' => 'samir', 'password' => 'nouveau-mdp']);
        $worker->refresh();
        $this->assertFalse($worker->is_admin);
        $this->assertTrue(\Hash::check('nouveau-mdp', $worker->password));
    }

    public function test_new_password_closes_the_other_sessions_of_the_account(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->create(['username' => 'samir', 'remember_token' => 'ancien-jeton']);
        DB::table('sessions')->insert([
            ['id' => 'tel-samir', 'user_id' => $worker->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'pc-admin', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()],
        ]);

        // sans nouveau mot de passe : rien ne change
        $this->actingAs($admin)->put("/users/{$worker->id}", ['name' => 'Samir', 'username' => 'samir'])->assertRedirect('/users');
        $this->assertSame('ancien-jeton', $worker->fresh()->remember_token);
        $this->assertTrue(DB::table('sessions')->where('id', 'tel-samir')->exists());

        $this->actingAs($admin)->put("/users/{$worker->id}", ['name' => 'Samir', 'username' => 'samir', 'password' => 'nouveau-mdp'])
            ->assertRedirect('/users');
        $this->assertNotSame('ancien-jeton', $worker->fresh()->remember_token);
        $this->assertFalse(DB::table('sessions')->where('id', 'tel-samir')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'pc-admin')->exists());
    }

    public function test_admin_box_keeps_the_submitted_value_after_an_error(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'boss']);
        $other = User::factory()->admin()->create(['username' => 'samir']);
        $checked = 'name="is_admin" value="1" checked';

        // case decochee + nom deja pris : la page revient avec la case decochee, pas avec l'ancienne valeur
        // (pas d'assertSessionHasErrors ici : il redemarre la session de test et les erreurs ne passent plus a la page suivante)
        $this->actingAs($admin)->from("/users/{$other->id}/edit")
            ->put("/users/{$other->id}", ['name' => 'Samir', 'username' => 'boss'])
            ->assertRedirect("/users/{$other->id}/edit");
        $this->actingAs($admin)->get("/users/{$other->id}/edit")->assertOk()
            ->assertSee('اسم المستخدم مستعمل من حساب آخر')
            ->assertDontSee($checked, false);
        $this->assertTrue($other->fresh()->is_admin);

        // sans erreur : la valeur enregistree
        $this->actingAs($admin)->get("/users/{$other->id}/edit")->assertSee($checked, false);
    }

    public function test_an_admin_cannot_demote_or_delete_himself(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'boss']);
        $other = User::factory()->create(['username' => 'pris']);

        $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => 'Boss', 'username' => 'boss', 'password' => 'nouveau-mdp'])
            ->assertSessionHasErrors('is_admin')
            ->assertSessionHasInput('username', 'boss')
            ->assertSessionMissing('_old_input.password');
        $this->assertTrue($admin->fresh()->is_admin);
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->assertNotNull($admin->fresh());

        $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => 'Boss', 'username' => 'pris', 'is_admin' => '1'])->assertSessionHasErrors('username');
        $this->actingAs($admin)->post('/users', ['name' => 'X', 'username' => 'nouveau', 'password' => 'court'])->assertSessionHasErrors('password');

        $this->actingAs($admin)->delete("/users/{$other->id}")->assertRedirect('/users');
        $this->assertNull($other->fresh());
    }

    public function test_user_create_command_admin_option(): void
    {
        $this->artisan('user:create', ['username' => 'chef', '--password' => 'chef-2026', '--admin' => true])->assertSuccessful();
        $this->artisan('user:create', ['username' => 'agent1', '--password' => 'agent-2026'])->assertSuccessful();
        // sans --admin, un compte existant garde ses droits
        $this->artisan('user:create', ['username' => 'chef', '--password' => 'autre-2026'])->assertSuccessful();

        $this->assertTrue(User::where('username', 'chef')->first()->is_admin);
        $this->assertFalse(User::where('username', 'agent1')->first()->is_admin);
    }
}
