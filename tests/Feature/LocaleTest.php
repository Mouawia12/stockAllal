<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_right_to_left_by_default(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('تسجيل الدخول')
            ->assertSee('name="locale" value="fr"', false);
    }

    public function test_switching_language_keeps_it_in_a_cookie_and_returns_to_the_page(): void
    {
        $res = $this->from('/login')->post('/locale', ['locale' => 'fr'])->assertRedirect('/login')->assertCookie('locale', 'fr', false);
        // lu aussi par public/offline.html (JavaScript)
        $this->assertFalse($res->getCookie('locale', false)->isHttpOnly());

        $this->withUnencryptedCookie('locale', 'fr')->get('/login')->assertOk()
            ->assertSee('<html lang="fr" dir="ltr">', false)
            ->assertSee('Connexion')
            ->assertDontSee('تسجيل الدخول');
        $this->withUnencryptedCookie('locale', 'en')->get('/login')->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Sign in');
    }

    public function test_unknown_languages_are_ignored(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'de'])->assertRedirect('/login')->assertSessionHasErrors('locale')->assertCookieMissing('locale');
        $this->withUnencryptedCookie('locale', 'de')->get('/login')->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_messages_errors_and_dates_follow_the_language(): void
    {
        $this->withUnencryptedCookie('locale', 'en')->post('/login', [])->assertSessionHasErrors(['username' => 'Username is required']);

        $worker = User::factory()->withPermissions([])->create();
        $this->withUnencryptedCookie('locale', 'en')->actingAs($worker)->get('/branches')
            ->assertForbidden()->assertSee('You do not have permission for this page');

        $admin = User::factory()->admin()->create();
        $admin->forceFill(['last_login_at' => now()->subMinutes(3)])->save();
        $this->withUnencryptedCookie('locale', 'fr')->actingAs($admin)->get('/users')->assertOk()
            ->assertSee('Utilisateurs')->assertSee('il y a 3 minutes');
    }

    /** Chaque texte existe dans les trois langues, avec les memes :marqueurs. */
    public function test_every_language_has_the_same_keys_and_placeholders(): void
    {
        $markers = fn ($text) => preg_match_all('/:([a-z_]+)/', (string) $text, $m) ? collect($m[1])->unique()->sort()->values()->all() : [];

        foreach (glob(lang_path('ar/*.php')) as $file) {
            $group = basename($file, '.php');
            $ar = Arr::dot(require $file);
            foreach (['fr', 'en'] as $locale) {
                $other = Arr::dot(require lang_path("{$locale}/{$group}.php"));
                $this->assertEqualsCanonicalizing(array_keys($ar), array_keys($other), "{$locale}/{$group}.php");
                foreach ($ar as $key => $text) {
                    $this->assertSame($markers($text), $markers($other[$key]), "{$locale}: {$group}.{$key}");
                }
            }
        }
    }
}
