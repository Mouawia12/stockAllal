<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_lands_on_the_apps_screen(): void
    {
        $this->get('/login')->assertOk()->assertSee('تسجيل الدخول')->assertSee(__('common.available'));

        User::factory()->create(['username' => 'karim', 'password' => 'karim-2026']);
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/');
        // deja connecte : /login renvoie aussi aux applications
        $this->get('/login')->assertRedirect('/');
    }

    public function test_inventory_opens_and_the_sales_apps_need_sales_view(): void
    {
        $worker = User::factory()->create();

        $page = $this->actingAs($worker)->get('/')->assertOk()
            ->assertSee(route('stock.index'))->assertSee('المخزون')
            // "الإعدادات" pour tous : un employe y trouve son propre compte
            ->assertSee('الإعدادات')->assertSee(route('settings'))
            // sans sales.view : ni ventes, ni achats, ni clients, ni fournisseurs
            ->assertDontSee(route('sales.index'))->assertDontSee('المبيعات')->assertDontSee(route('customers.index'))->assertDontSee('الموردون');
        $this->assertSame(0, substr_count($page->getContent(), 'class="soon-pill"'));

        // avec sales.view : les quatre s'ouvrent
        $seller = User::factory()->withPermissions(['sales.view'])->create();
        $this->actingAs($seller)->get('/')->assertOk()->assertSee(['المبيعات', 'المشتريات', 'الزبائن', 'الموردون'])
            ->assertSee(route('sales.index'))->assertSee(route('purchases.index'))
            ->assertSee(route('customers.index'))->assertSee(route('suppliers.index'));

        // dans l'application : son nom et le bouton de retour aux applications
        $this->actingAs($worker)->get('/stock')->assertOk()->assertSee('المخزون')->assertSee('aria-label="التطبيقات"', false);
    }

    public function test_settings_opens_the_accounts_for_an_admin_and_his_own_account_for_others(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('الإعدادات')->assertSee(route('settings'));
        $this->actingAs($admin)->get('/settings')->assertRedirect(route('users.index'));
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('الإعدادات');

        $this->actingAs($worker)->get('/settings')->assertRedirect(route('account.edit'));
        // dans l'application "الإعدادات" : son compte, sans le menu des comptes
        $this->actingAs($worker)->get('/account')->assertOk()->assertSee('الإعدادات')->assertDontSee(route('users.index'));
    }

    public function test_every_page_shows_the_inventory_box_in_the_browser_tab(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $file) {
            $this->assertGreaterThan(0, filesize(public_path($file)), $file);
        }

        $this->get('/login')->assertOk()->assertSee('favicon.svg', false)->assertSee('favicon.ico', false);
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('favicon.svg', false);

        Route::get('/_boom', fn () => abort(500));
        $this->get('/_boom')->assertStatus(500)->assertSee('favicon.svg', false);

        // offline.html s'affiche sans le serveur : son icone est dans la page meme
        $this->assertStringContainsString('rel="icon" type="image/svg+xml" href="data:image/svg+xml,', file_get_contents(public_path('offline.html')));
    }
}
