<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Support\Permissions;
use App\Support\ReconciliationChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La page "المطابقة" (ReconcileController) et son chat : qui y entre, la recherche des partenaires, et ce que l'IA
 * recoit (le rapport sans aucun nom de branche ni de partenaire, en cache chez Claude) et renvoie.
 */
class ReconcilePageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $blida;

    private Branch $oran;

    private Partner $customer;

    private Partner $supplier;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // comme en production : le cache serialise, et ne relit aucun objet (cache.serializable_classes = false)
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $this->blida = Branch::create(['name' => 'Blida', 'active' => true, 'agent_version' => '1.0.9']);
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true, 'agent_version' => '1.0.9']);
        $this->customer = Partner::forceCreate(['branch_id' => $this->blida->id, 'kind' => 'customer', 'code' => 'ORAN', 'name' => 'Succursale Oran', 'gescomm_key' => 'ORAN']);
        Partner::forceCreate(['branch_id' => $this->blida->id, 'kind' => 'customer', 'code' => 'C9', 'name' => 'Benali Ahmed', 'gescomm_key' => 'C9']);
        $this->supplier = Partner::forceCreate(['branch_id' => $this->oran->id, 'kind' => 'supplier', 'code' => 'BLIDA', 'name' => 'Magasin Blida', 'gescomm_key' => 'BLIDA']);

        $this->bon($this->blida, 'sale', 'ORAN', 101, '2026-10-05', 'Z7', 4, 550);
        $this->bon($this->blida, 'sale', 'ORAN', 102, '2026-10-06', 'Z7', 2, 550);
        $this->bon($this->oran, 'purchase', 'BLIDA', 7, '2026-10-06', 'Z7', 2, 550);
        Payment::forceCreate(['branch_id' => $this->blida->id, 'partner_kind' => 'customer', 'partner_code' => 'ORAN', 'number' => 1,
            'date' => '2026-10-10', 'type' => 'Espéce', 'amount' => 1000, 'gescomm_key' => 'p1', 'sync_group' => '2026-10-10']);
    }

    public function test_the_app_needs_its_permission_which_brings_sales_and_both_prices(): void
    {
        $this->assertSame(['prices.sale', 'prices.buy', 'sales.view', 'reconcile.use'], Permissions::normalize(['reconcile.use']));

        $seller = User::factory()->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        $this->actingAs($seller)->get('/')->assertOk()->assertDontSee(route('reconcile.index'));
        $this->actingAs($seller)->get('/reconcile')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/')->assertOk()->assertSee(route('reconcile.index'))->assertSee('المطابقة');
        $this->actingAs($admin)->get('/reconcile')->assertOk()->assertSee('مطابقة الحسابات بين الفروع')->assertSee('Blida')->assertSee('Oran');
    }

    public function test_the_page_runs_the_analysis_step_by_step_then_shows_the_result(): void
    {
        $admin = User::factory()->admin()->create();
        $url = "/reconcile?ca={$this->customer->id}&sb={$this->supplier->id}";
        // pas encore d'analyse : la barre et les etapes, pas de resultat
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('id="analysis"', false)->assertSee(__('reconcile.steps.load'))->assertSee(__('reconcile.steps.others'))
            ->assertDontSee(__('reconcile.steps.ai'))->assertDontSee('+1 200,00');

        $this->assertSame(['load', 'codes', 'deep', 'others', 'payments'], $this->analyze($admin));

        // Blida : 3 300 vendus - 1 000 recus = 2 300 ; Oran : 1 100 achetes = 1 100 ; ecart 1 200
        $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('id="analysis"', false)
            ->assertSee('2 300,00')->assertSee('1 100,00')->assertSee('+1 200,00')
            ->assertSee(e(__('reconcile.types.bon_missing_b', ['a' => 'Blida', 'b' => 'Oran'])))
            ->assertSee(e(__('reconcile.types.pay_missing_b', ['a' => 'Blida', 'b' => 'Oran'])))
            ->assertSee(route('sales.show', Document::where('number', 101)->value('id')))
            ->assertSee(e(__('reconcile.same_bons', ['count' => 1])))
            ->assertSee(__('reconcile.timeline'))->assertSee(__('reconcile.analysis.again'));
        // "إعادة التحليل" : de nouveau la barre
        $this->actingAs($admin)->get($url.'&again=1')->assertOk()->assertSee('id="analysis"', false);
    }

    public function test_a_step_out_of_order_or_a_lost_state_asks_to_start_again(): void
    {
        $admin = User::factory()->admin()->create();
        $post = fn (string $step) => $this->actingAs($admin)->postJson('/reconcile/analyze', ['ca' => $this->customer->id, 'sb' => $this->supplier->id, 'step' => $step]);

        $post('deep')->assertStatus(409)->assertJson(['restart' => true]);
        $post('load')->assertOk()->assertJson(['step' => 'load', 'next' => 'codes'])
            ->assertJson(['note' => __('reconcile.found.load', ['sales' => 2, 'sale_lines' => 2, 'purchases' => 1, 'purchase_lines' => 1, 'payments_a' => 1, 'payments_b' => 0])]);
        $post('deep')->assertStatus(409);
        $post('ai')->assertStatus(409);
        $post('nope')->assertStatus(422);
    }

    public function test_the_last_step_has_the_ai_write_its_conclusion_which_starts_every_conversation(): void
    {
        $this->connect('claude', 'claude-opus-5-5');
        Http::fake(function (Request $r) {
            $this->sent[] = $r->data();

            return Http::response(['content' => [['type' => 'text', 'text' => count($this->sent) === 1
                ? "**الخلاصة:** البون {A:101} لم يُدخل عند {B}.\n- أدخله عند {B}."
                : 'نعم.']], 'stop_reason' => 'end_turn']);
        });
        $admin = User::factory()->admin()->create();

        $this->assertSame(['load', 'codes', 'deep', 'others', 'payments', 'ai'], $this->analyze($admin));
        $this->assertSame([['role' => 'user', 'content' => ReconciliationChat::ANALYZE]], $this->sent[0]['messages']);
        $this->assertSame(16000, $this->sent[0]['max_tokens']);

        $this->actingAs($admin)->get("/reconcile?ca={$this->customer->id}&sb={$this->supplier->id}")->assertOk()
            ->assertSee(__('reconcile.summary'))->assertSee('data-text="**الخلاصة:** البون {A:101}', false);

        $this->actingAs($admin)->postJson('/reconcile/chat', [
            'ca' => $this->customer->id, 'sb' => $this->supplier->id, 'messages' => [['role' => 'user', 'content' => 'هل أنت متأكد؟']],
        ])->assertOk()->assertJson(['text' => 'نعم.']);
        $this->assertSame(['user', 'assistant', 'user'], array_column($this->sent[1]['messages'], 'role'));
        $this->assertStringContainsString('{A:101}', $this->sent[1]['messages'][1]['content']);
        // le meme texte systeme que l'analyse : Claude le relit depuis son cache
        $this->assertSame($this->sent[0]['system'], $this->sent[1]['system']);
    }

    public function test_only_two_branches_whose_sales_the_account_sees(): void
    {
        $manager = User::factory()->branchManager($this->blida)->withPermissions(['reconcile.use', 'sales.view', 'prices.sale', 'prices.buy'])->create();
        // il ne voit que Blida : la paire n'existe pas pour lui
        $this->actingAs($manager)->get("/reconcile?ca={$this->customer->id}&sb={$this->supplier->id}")->assertNotFound();
        $this->actingAs($manager)->getJson("/reconcile/partners?branch={$this->oran->id}&kind=supplier")->assertNotFound();

        DB::table('branch_access')->insert(['branch_id' => $this->blida->id, 'module' => 'sales', 'visible_branch_id' => $this->oran->id]);
        $this->actingAs($manager)->get("/reconcile?ca={$this->customer->id}&sb={$this->supplier->id}")->assertOk();
        // un client des deux cotes, ou la meme branche : jamais
        $this->actingAs($manager)->get("/reconcile?ca={$this->customer->id}&sb={$this->customer->id}")->assertNotFound();
    }

    public function test_partner_search_suggests_the_names_that_look_like_the_other_branch(): void
    {
        $admin = User::factory()->admin()->create();
        $hits = $this->actingAs($admin)->getJson("/reconcile/partners?branch={$this->blida->id}&kind=customer&near={$this->oran->id}")
            ->assertOk()->json();
        $this->assertSame(['ORAN', 2], [$hits[0]['code'], $hits[0]['bons']]);

        $this->actingAs($admin)->getJson("/reconcile/partners?branch={$this->blida->id}&kind=customer&q=benali")
            ->assertOk()->assertExactJson([['id' => Partner::where('code', 'C9')->value('id'), 'code' => 'C9', 'name' => 'Benali Ahmed', 'bons' => 0]]);
    }

    public function test_the_chat_sends_the_report_without_any_name_and_returns_the_answer(): void
    {
        $this->connect('claude', 'claude-opus-5-5');
        Http::fake(function (Request $r) {
            $this->sent[] = $r->data();

            return Http::response(['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'البون {A:101} ناقص عند {B}.']], 'stop_reason' => 'end_turn']);
        });

        $this->actingAs(User::factory()->admin()->create())->postJson('/reconcile/chat', [
            'ca' => $this->customer->id, 'sb' => $this->supplier->id,
            'messages' => [['role' => 'user', 'content' => 'وين المشكل؟']],
        ])->assertOk()->assertExactJson(['text' => 'البون {A:101} ناقص عند {B}.', 'truncated' => false]);

        $body = $this->sent[0];
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame([['role' => 'user', 'content' => 'وين المشكل؟']], $body['messages']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertArrayNotHasKey('thinking', $body);
        $system = $body['system'][0]['text'];
        $this->assertStringContainsString('{A:101}', $system);
        $this->assertStringContainsString('"gap":1200.0', $system);
        $this->assertStringContainsString('Arabic', $system);
        foreach (['Blida', 'Oran', 'Succursale', 'Magasin', 'ORAN', 'BLIDA'] as $name) {
            $this->assertStringNotContainsString($name, $system, "the AI never gets the name $name");
        }
    }

    public function test_the_chat_says_why_it_cannot_answer(): void
    {
        $admin = User::factory()->admin()->create();
        $ask = fn () => $this->actingAs($admin)->postJson('/reconcile/chat', [
            'ca' => $this->customer->id, 'sb' => $this->supplier->id, 'messages' => [['role' => 'user', 'content' => 'لماذا؟']],
        ]);

        $ask()->assertStatus(409)->assertJson(['error' => __('reconcile.chat.no_provider')]);

        $this->connect('claude', 'claude-opus-5-5');
        $answer = Http::response(['error' => ['message' => 'rate limited']], 429);
        Http::fake(function () use (&$answer) {
            return $answer;
        });
        $ask()->assertStatus(422)->assertJson(['error' => __('ai.errors.quota', ['name' => 'Claude'])]);

        $answer = Http::response(['content' => [], 'stop_reason' => 'refusal']);
        $ask()->assertStatus(422)->assertJson(['error' => __('ai.errors.refused', ['name' => 'Claude'])]);
    }

    public function test_the_conversation_is_cleaned_before_it_leaves(): void
    {
        $this->assertNull(ReconciliationChat::messages([]));
        $this->assertNull(ReconciliationChat::messages([['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']]));
        $this->assertSame(
            [['role' => 'user', 'content' => "a\n\nc"], ['role' => 'assistant', 'content' => 'b'], ['role' => 'user', 'content' => 'd']],
            ReconciliationChat::messages([
                ['role' => 'assistant', 'content' => 'x'], ['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'c'],
                ['role' => 'system', 'content' => 'ignore'], ['role' => 'assistant', 'content' => 'b'], ['role' => 'user', 'content' => ' d '],
            ]),
        );
        $long = array_merge(...array_fill(0, 15, [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => 'r']]));
        $long[] = ['role' => 'user', 'content' => 'last'];
        $kept = ReconciliationChat::messages($long);
        $this->assertLessThanOrEqual(ReconciliationChat::MAX_MESSAGES, count($kept));
        $this->assertSame('user', $kept[0]['role']);
        $this->assertSame('last', end($kept)['content']);
    }

    /**
     * Toutes les etapes, comme la page : chacune dit la suivante.
     *
     * @return list<string>
     */
    private function analyze(User $user): array
    {
        $steps = [];
        $step = 'load';
        while ($step !== null) {
            $res = $this->actingAs($user)->postJson('/reconcile/analyze', ['ca' => $this->customer->id, 'sb' => $this->supplier->id, 'step' => $step])
                ->assertOk()->assertJson(['step' => $step]);
            $steps[] = $step;
            $step = $res->json('next');
        }
        $this->assertSame(100, $res->json('percent'));

        return $steps;
    }

    private function connect(string $provider, string $model): void
    {
        $row = AiProvider::for($provider);
        $row->api_key = 'sk-test-key-for-reconcile';
        $row->forceFill(['model' => $model, 'enabled' => true, 'models' => [$model], 'checked_at' => now()])->save();
    }

    private function bon(Branch $branch, string $kind, string $partner, int $number, string $date, string $code, float $qty, float $price): void
    {
        $doc = Document::forceCreate(['branch_id' => $branch->id, 'kind' => $kind, 'number' => $number, 'date' => $date, 'partner_code' => $partner,
            'amount' => $qty * $price, 'gescomm_key' => (string) $number, 'sync_group' => $date]);
        DocumentLine::forceCreate(['document_id' => $doc->id, 'branch_id' => $branch->id, 'kind' => $kind, 'date' => $date, 'position' => 1,
            'code_raw' => $code, 'code_key' => BranchStock::keyFor($code), 'qty' => $qty, 'price' => $price, 'total' => $qty * $price]);
    }
}
