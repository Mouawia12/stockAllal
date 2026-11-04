<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Page "الذكاء الاصطناعي" (AiSettingsController) : le developpeur seulement ; la cle chiffree en base, testee avant d'etre gardee,
 * jamais renvoyee au navigateur ni ecrite au journal.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CLAUDE_KEY = 'sk-ant-api03-test-key-0000-wxyz';

    /** La reponse des fournisseurs, changee d'une etape a l'autre (un second Http::fake passerait apres le premier). */
    private \Closure $answer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->answer = fn () => throw new \LogicException('aucun appel attendu');
        Http::fake(fn (Request $r) => ($this->answer)($r));
    }

    private function answer(array|\Closure $body, int $status = 200): void
    {
        $this->answer = $body instanceof \Closure ? $body : fn () => Http::response($body, $status);
    }

    public function test_only_the_developer_opens_it(): void
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        foreach ([User::factory()->owner()->create(), User::factory()->branchManager($oran)->create(), User::factory()->inBranch($oran)->create()] as $user) {
            $this->actingAs($user)->get('/settings/ai')->assertForbidden();
            $this->actingAs($user)->put('/settings/ai/claude', ['api_key' => self::CLAUDE_KEY])->assertForbidden();
            $this->actingAs($user)->get('/account')->assertOk()->assertDontSee(route('ai.index'));
        }
        $this->assertSame(0, AiProvider::count());

        $dev = User::factory()->admin()->create();
        $this->actingAs($dev)->get('/settings/ai')->assertOk()->assertSee('Claude')->assertSee('ChatGPT')->assertSee('Gemini');
        $this->actingAs($dev)->get('/users')->assertSee(route('ai.index'));
        $this->actingAs($dev)->get('/settings/ai/mistral/test')->assertNotFound();
        $this->actingAs($dev)->post('/settings/ai/mistral/test')->assertNotFound();
    }

    public function test_a_key_is_tested_then_kept_encrypted_and_never_shown(): void
    {
        $this->answer(['data' => [['id' => 'claude-opus-5-5', 'type' => 'model'], ['id' => 'claude-sonnet-5-5', 'type' => 'model']]]);
        $dev = User::factory()->admin()->create();

        $this->actingAs($dev)->from('/settings/ai')->put('/settings/ai/claude', ['api_key' => '  '.self::CLAUDE_KEY.' ', 'enabled' => '1'])
            ->assertRedirect('/settings/ai')->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.anthropic.com/v1/models')
            && $r->hasHeader('x-api-key', self::CLAUDE_KEY) && $r->hasHeader('anthropic-version') && ! str_contains($r->url(), self::CLAUDE_KEY));

        $row = AiProvider::where('provider', 'claude')->firstOrFail();
        $this->assertSame(self::CLAUDE_KEY, $row->api_key);
        $this->assertSame('claude-opus-5-5', $row->model, 'le plus recent, sans choix');
        $this->assertTrue($row->enabled);
        $this->assertTrue($row->connected());
        $raw = (string) DB::table('ai_providers')->value('api_key');
        $this->assertStringNotContainsString(self::CLAUDE_KEY, $raw, 'chiffree en base');
        $this->assertStringNotContainsString('wxyz', $raw);
        $this->assertArrayNotHasKey('api_key', $row->toArray());

        $html = $this->actingAs($dev)->get('/settings/ai')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::CLAUDE_KEY, $html);
        foreach (['•••• wxyz', 'claude-sonnet-5-5', __('ai.state.on'), __('ai.test')] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        // choisir un autre modele sans retaper la cle : pas de nouvel appel, la cle reste
        $this->answer(fn () => throw new \LogicException('aucun appel attendu'));
        $this->actingAs($dev)->put('/settings/ai/claude', ['api_key' => '', 'model' => 'claude-sonnet-5-5', 'enabled' => '1'])->assertSessionHas('status');
        $row->refresh();
        $this->assertSame([self::CLAUDE_KEY, 'claude-sonnet-5-5'], [$row->api_key, $row->model]);

        // le journal dit que la cle a change, jamais laquelle
        $log = ActivityLog::where('action', 'ai.updated')->orderBy('id')->firstOrFail();
        $this->assertStringNotContainsString(self::CLAUDE_KEY, json_encode($log->data));
        $this->actingAs($dev)->get('/activity')->assertOk()->assertSee('عدّل ربط Claude')
            ->assertSeeInOrder(['المفتاح', 'تغيّرت'])->assertSeeInOrder(['النموذج', 'claude-opus-5-5', '←', 'claude-sonnet-5-5'])
            ->assertDontSee(self::CLAUDE_KEY);
    }

    public function test_a_refused_key_is_not_kept_and_a_silent_provider_keeps_it_with_the_error(): void
    {
        $dev = User::factory()->admin()->create();

        // OpenAI refuse la cle et en ecrit une partie dans son message
        $this->answer(['error' => ['message' => 'Incorrect API key provided: sk-bad-1234.']], 401);
        $this->actingAs($dev)->from('/settings/ai')->put('/settings/ai/openai', ['api_key' => 'sk-bad-1234', 'enabled' => '1'])
            ->assertRedirect('/settings/ai')->assertSessionHasErrors('api_key');
        $this->assertNull(AiProvider::where('provider', 'openai')->first());

        // Gemini sans reponse (reseau) : la cle est gardee, l'erreur se voit sur sa carte
        $this->answer(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->actingAs($dev)->put('/settings/ai/gemini', ['api_key' => 'AIza-test-key-9876'])->assertSessionHasErrors('api_key');
        $row = AiProvider::where('provider', 'gemini')->firstOrFail();
        $this->assertSame(['AIza-test-key-9876', 'unreachable', false], [$row->api_key, $row->check_error, $row->connected()]);
        $this->actingAs($dev)->get('/settings/ai')->assertSee(__('ai.errors.unreachable', ['name' => 'Gemini']))->assertSee('cURL error 28')->assertDontSee('AIza-test-key-9876');

        // un nouveau test, la cle dans un en-tete et jamais dans l'URL ; seuls les modeles de texte, stables d'abord
        $this->answer(['models' => [
            ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-3.0-pro-preview', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
        ]]);
        $this->actingAs($dev)->post('/settings/ai/gemini/test')->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://generativelanguage.googleapis.com/')
            && $r->hasHeader('x-goog-api-key', 'AIza-test-key-9876') && ! str_contains($r->url(), 'AIza'));
        $row->refresh();
        $this->assertSame(['gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-3.0-pro-preview'], $row->models);
        $this->assertSame(['gemini-2.5-pro', null], [$row->model, $row->check_error]);

        // la liste d'OpenAI : seulement la conversation
        $this->answer(['data' => [
            ['id' => 'whisper-1', 'created' => 5], ['id' => 'gpt-4o', 'created' => 3], ['id' => 'text-embedding-3-small', 'created' => 6],
            ['id' => 'gpt-4o-realtime-preview', 'created' => 7], ['id' => 'o3-mini', 'created' => 4], ['id' => 'dall-e-3', 'created' => 8],
        ]]);
        $this->actingAs($dev)->put('/settings/ai/openai', ['api_key' => 'sk-good-5678'])->assertSessionHas('status');
        $this->assertSame(['o3-mini', 'gpt-4o'], AiProvider::where('provider', 'openai')->firstOrFail()->models);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/v1/models') && $r->hasHeader('Authorization', 'Bearer sk-good-5678'));
    }

    public function test_enabling_needs_a_key_and_removing_the_key_disables_it(): void
    {
        $dev = User::factory()->admin()->create();
        $this->actingAs($dev)->put('/settings/ai/claude', ['enabled' => '1'])->assertSessionHasErrors('api_key');
        $this->actingAs($dev)->post('/settings/ai/claude/test')->assertSessionHasErrors('api_key');
        $this->assertSame(0, AiProvider::count());

        $this->answer(['data' => [['id' => 'claude-haiku-5-5']]]);
        $this->actingAs($dev)->put('/settings/ai/claude', ['api_key' => self::CLAUDE_KEY, 'enabled' => '1']);
        $this->actingAs($dev)->delete('/settings/ai/claude/key')->assertSessionHas('status');
        $row = AiProvider::where('provider', 'claude')->firstOrFail();
        $this->assertSame([null, false, null], [$row->api_key, $row->enabled, $row->models]);
        $this->assertFalse($row->hasStoredKey());
        $this->assertSame(1, ActivityLog::where('action', 'ai.key_removed')->count());
        $this->actingAs($dev)->get('/settings/ai')->assertSee(__('ai.state.not_set'));
    }

    public function test_a_key_encrypted_with_another_app_key_asks_to_be_entered_again(): void
    {
        $dev = User::factory()->admin()->create();
        DB::table('ai_providers')->insert(['provider' => 'claude', 'api_key' => 'eyJpdiI6ImJyb2tlbiJ9', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($dev)->get('/settings/ai')->assertOk()->assertSee(__('ai.state.unreadable'));
        $this->actingAs($dev)->post('/settings/ai/claude/test')->assertSessionHasErrors('api_key');
        Http::assertNothingSent();
    }
}
