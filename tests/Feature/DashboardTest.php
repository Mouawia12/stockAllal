<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Branch $branch, string $code, ?string $designation, ?float $stock): void
    {
        BranchStock::create([
            'branch_id' => $branch->id,
            'code_raw' => $code,
            'code_key' => BranchStock::keyFor($code),
            'designation' => $designation,
            'stock' => $stock,
            'seen_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBranches(): array
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $setif = Branch::create(['name' => 'Setif', 'active' => true]);
        $off = Branch::create(['name' => 'Ancienne', 'active' => false]);
        $this->stock($oran, '820ac', 'BOULON 8X20', 10);
        $this->stock($oran, '820-AC', 'BOULON 8*20 autre ecriture', 1);
        $this->stock($setif, '820AC', 'BOULLON ACIER EX 8*20 (G3)', 164.11);
        $this->stock($setif, '612Z', 'BOULLON ZINGUEE 6*12', -3);
        $this->stock($off, '820AC', 'BRANCHE DESACTIVEE', 1000);

        return [$oran, $setif, $off];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/product?code=X')->assertUnauthorized();
        $this->get('/branches')->assertRedirect('/login');
    }

    public function test_login_with_username(): void
    {
        $user = User::factory()->create(['username' => '0600000000', 'password' => 'secret-123']);

        $this->post('/login', ['username' => '0600000000', 'password' => 'mauvais'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => '0600000000', 'password' => 'secret-123'])
            ->assertRedirect('/')->assertSessionHas('weak_password', false);
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_equal_to_username_shows_a_reminder_until_changed(): void
    {
        $user = User::factory()->create(['username' => '0600000000', 'password' => '0600000000']);

        $this->post('/login', ['username' => '0600000000', 'password' => '0600000000'])->assertSessionHas('weak_password', true);
        $this->get('/')->assertSee('كلمة السر الحالية تساوي اسم المستخدم');

        $this->put('/account', [
            'name' => 'Admin', 'username' => 'admin', 'current_password' => '0600000000',
            'password' => 'nouveau-123', 'password_confirmation' => 'nouveau-123',
        ])->assertSessionHasNoErrors()->assertSessionHas('weak_password', false);

        $user->refresh();
        $this->assertSame('admin', $user->username);
        $this->post('/logout');
        $this->post('/login', ['username' => 'admin', 'password' => 'nouveau-123'])->assertRedirect('/');
    }

    public function test_account_update_requires_the_current_password(): void
    {
        $user = User::factory()->create(['username' => 'admin', 'password' => 'secret-123']);
        User::factory()->create(['username' => 'pris']);

        $this->actingAs($user)->put('/account', ['name' => 'X', 'username' => 'nouveau', 'current_password' => 'faux'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/account', ['name' => 'X', 'username' => 'pris', 'current_password' => 'secret-123'])
            ->assertSessionHasErrors('username');
        $this->assertSame('admin', $user->fresh()->username);
    }

    public function test_new_password_on_account_page_closes_the_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['username' => 'karim', 'password' => 'secret-123', 'remember_token' => 'ancien-jeton']);
        DB::table('sessions')->insert(['id' => 'tel-karim', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $recaller = Auth::guard()->getRecallerName();
        $form = ['name' => 'Karim', 'username' => 'karim', 'current_password' => 'secret-123'];

        // sans nouveau mot de passe : rien ne change
        $this->actingAs($user)->put('/account', $form)->assertSessionHasNoErrors()->assertCookieMissing($recaller);
        $this->assertSame('ancien-jeton', $user->fresh()->remember_token);
        $this->assertTrue(DB::table('sessions')->where('id', 'tel-karim')->exists());

        // connecte avec "تذكّرني" : le telephone est deconnecte, ce navigateur recoit un cookie avec la nouvelle cle
        $this->actingAs($user)->withCookie($recaller, 'ancien-cookie')
            ->put('/account', $form + ['password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])
            ->assertSessionHasNoErrors()->assertCookie($recaller);
        $this->assertNotSame('ancien-jeton', $user->fresh()->remember_token);
        $this->assertFalse(DB::table('sessions')->where('id', 'tel-karim')->exists());
    }

    public function test_agent_polling_does_not_use_up_login_attempts_from_the_same_ip(): void
    {
        $branch = Branch::create(['name' => 'Oran', 'active' => true, 'token_hash' => Branch::hashToken('tok')]);
        for ($i = 0; $i < 10; $i++) {
            $this->withToken('tok')->getJson('/api/agent/pending')->assertOk();
        }
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->withToken('')->post('/login', ['username' => $user->username, 'password' => 'secret-123'])->assertRedirect('/');
        $this->assertTrue($branch->fresh()->isOnline());
    }

    public function test_search_returns_one_line_per_code_key(): void
    {
        $this->seedBranches();
        $user = User::factory()->create();

        $results = $this->actingAs($user)->get('/stock?q=820')->assertOk()->viewData('results')->keyBy('code');

        $this->assertSame(['820-AC', '820AC'], $results->keys()->sort()->values()->all());
        // 820ac (Oran) et 820AC (Setif) = un seul produit ; la branche desactivee est ignoree
        $this->assertEquals(174.11, $results['820AC']->total);
        $this->assertSame(2, $results['820AC']->branches);
        $this->assertSame('BOULLON ACIER EX 8*20 (G3)', $results['820AC']->designation, 'designation de la branche la mieux stockee');
        // autre ecriture du code = autre ligne, affichee telle quelle
        $this->assertEquals(1, $results['820-AC']->total);
    }

    public function test_search_by_designation_and_exact_code_first(): void
    {
        $this->seedBranches();
        $user = User::factory()->create();

        $codes = $this->actingAs($user)->get('/stock?q=zinguee')->viewData('results')->pluck('code')->all();
        $this->assertSame(['612Z'], $codes);

        $codes = $this->actingAs($user)->get('/stock?q=820AC')->viewData('results')->pluck('code')->all();
        $this->assertSame('820AC', $codes[0]);

        $this->actingAs($user)->get('/stock?q=%25')->assertOk()->assertViewHas('results', fn ($r) => $r->isEmpty());
    }

    public function test_product_lists_every_active_branch(): void
    {
        [$oran] = $this->seedBranches();
        $oran->update(['last_seen_at' => now()]);
        $user = User::factory()->create();

        $data = $this->actingAs($user)->getJson('/product?code=612z')->assertOk()->json();

        $this->assertSame('612Z', $data['code']);
        $this->assertSame(['Setif', 'Oran'], array_column($data['branches'], 'branch'), 'tri par quantite, les absents a la fin');
        $this->assertEquals(-3, $data['branches'][0]['rows'][0]['stock']);
        $this->assertSame([], $data['branches'][1]['rows']);
        $this->assertTrue($data['branches'][1]['online']);
        $this->assertFalse($data['done']);
    }

    public function test_agent_download_zip_and_guide(): void
    {
        $user = User::factory()->admin()->create();
        $this->get('/agent/download')->assertRedirect('/login');

        $res = $this->actingAs($user)->get('/agent/download')->assertOk();
        $this->assertStringContainsString('GescomAgent-', $res->headers->get('content-disposition'));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($res->getFile()->getPathname()));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame([
            'GescomAgent/GescomAgent-Guide.pdf', 'GescomAgent/README.md', 'GescomAgent/agent.ps1',
            'GescomAgent/diagnose.ps1', 'GescomAgent/install.ps1', 'GescomAgent/uninstall.ps1',
        ], $names);
        @unlink($res->getFile()->getPathname());

        $this->actingAs($user)->get('/agent/guide')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get('/branches')->assertSee('تنزيل الوكيل')->assertSee('theme-toggle', false);
    }

    public function test_branches_page_install_code_rename_and_disable(): void
    {
        [$oran] = $this->seedBranches();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/branches/install-code')->assertSessionHas('install_code');
        $code = session('install_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{3}-[A-Z2-9]{3}-[A-Z2-9]{3}$/', $code);
        $this->actingAs($user)->withSession(['install_code' => $code])->get('/branches')->assertOk()->assertSee($code);

        $this->actingAs($user)->patch("/branches/{$oran->id}", ['name' => 'فرع وهران'])->assertSessionHas('status');
        $this->actingAs($user)->patch("/branches/{$oran->id}", ['active' => 0]);
        $this->assertSame('فرع وهران', $oran->fresh()->name);
        $this->assertFalse($oran->fresh()->active);

        $this->actingAs($user)->delete("/branches/{$oran->id}");
        $this->assertSame(0, BranchStock::where('branch_id', $oran->id)->count());
    }
}
