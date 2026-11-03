<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function login(bool $remember = false): void
    {
        User::where('username', 'karim')->exists() || User::factory()->create(['username' => 'karim', 'password' => 'karim-2026']);
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026', 'remember' => $remember ? '1' : null])
            ->assertRedirect('/');
    }

    public function test_a_login_lasts_a_workday_even_while_in_use(): void
    {
        $this->assertSame(480, (int) config('session.lifetime'), '8 h par defaut (config/session.php, .env.example)');
        $this->login();

        // utilise toute la journee : toujours connecte juste avant les 8 h
        $this->travel(7)->hours();
        $this->get('/stock?q=8')->assertOk();
        $this->travel(59)->minutes();
        $this->get('/stock?q=8')->assertOk();

        // 8 h apres la connexion : page de connexion, avec la raison, puis retour a la meme page apres la reconnexion
        $this->travel(1)->minutes();
        $this->get('/stock?q=8')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('انتهت جلستك بعد 8 ساعات من الدخول');
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/stock?q=8');
    }

    public function test_page_calls_after_the_workday_get_a_401_or_go_to_the_login_page(): void
    {
        $this->login();
        $this->travel(8)->hours();
        $this->getJson('/product?code=820AC')->assertStatus(401)->assertJsonStructure(['message']);

        $this->login();
        $this->travel(8)->hours();
        // recherche pendant la frappe : la page se recharge (fetchRetry), on ne garde pas le fragment comme page de retour
        $this->get('/stock?q=8&partial=1')->assertRedirect('/login');
        $this->post('/login', ['username' => 'karim', 'password' => 'karim-2026'])->assertRedirect('/');
    }

    public function test_remember_me_is_the_choice_to_stay_signed_in(): void
    {
        $this->login(remember: true);
        $this->travel(9)->hours();
        $this->get('/stock')->assertOk();
    }
}
