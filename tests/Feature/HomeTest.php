<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_lands_on_the_apps_screen(): void
    {
        $this->get('/login')->assertOk()->assertSee('تسجيل الدخول')->assertSee('قريباً');

        User::factory()->create(['username' => 'karim', 'password' => 'karim-2026']);
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/');
        // deja connecte : /login renvoie aussi aux applications
        $this->get('/login')->assertRedirect('/');
    }

    public function test_inventory_opens_and_the_other_apps_are_coming_soon(): void
    {
        $worker = User::factory()->create();

        $page = $this->actingAs($worker)->get('/')->assertOk()
            ->assertSee(route('stock.index'))
            ->assertSee(['المخزون', 'المبيعات', 'المشتريات', 'الموردون', 'الزبائن'])
            ->assertDontSee('الإعدادات');
        $this->assertSame(4, substr_count($page->getContent(), 'class="soon-pill"'), 'المبيعات، المشتريات، الموردون، الزبائن');

        // dans l'application : son nom et le bouton de retour aux applications
        $this->actingAs($worker)->get('/stock')->assertOk()->assertSee('المخزون')->assertSee('aria-label="التطبيقات"', false);
    }

    public function test_admins_also_get_the_settings_app(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('الإعدادات')->assertSee(route('users.index'));
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('الإعدادات');
    }
}
