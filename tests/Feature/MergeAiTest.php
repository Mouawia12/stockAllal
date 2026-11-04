<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\MergeDismissal;
use App\Models\MergeScan;
use App\Models\MergeSuggestion;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\MergeCandidates;
use App\Support\MergeScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "اقتراحات الدمج بالذكاء الاصطناعي" : les candidats trouves ici (MergeCandidates), l'IA qui juge par lots (MergeScanner),
 * puis la liberte du rapport : tout, quelques groupes, un code retire, un groupe a la fois, "ليسا نفس الصنف".
 * Jamais une fusion sans clic ; jamais un prix ni une quantite envoyes a l'IA ; jamais une branche que le compte ne voit pas.
 */
class MergeAiTest extends TestCase
{
    use RefreshDatabase;

    /** Ce que l'IA repond, d'un appel a l'autre ; chaque requete est gardee pour verifier ce qui part. */
    private \Closure $answer;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private Branch $oran;

    private Branch $setif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->answer = fn () => throw new \LogicException('aucun appel attendu');
        Http::fake(function (Request $r) {
            $this->sent[] = $r->data();

            return ($this->answer)($r);
        });
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $this->setif = Branch::create(['name' => 'Setif', 'active' => true]);
    }

    private function item(Branch $branch, string $code, string $designation, float $stock = 5, ?float $price = 1234.5): void
    {
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => BranchStock::keyFor($code), 'designation' => $designation,
            'stock' => $stock, 'price' => $price, 'seen_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Le catalogue : 820Z partout (rien a fusionner) ; 820-AC a Oran = 820AC a Setif (code) ;
     * "VIS 6X40" a Oran = "VIS 6*40" a Setif (meme designation) ; "BOULON TH 8*20 ZINGUE" / "BOULON 8X20 ZING" (proches) ;
     * "BOULON 8*25" a Oran face a "BOULON 8*20 ..." : une autre mesure ; "ROBINET 1/2" / "VANNE 15*21" : seule l'IA peut les rapprocher.
     */
    private function catalog(): void
    {
        $this->item($this->oran, '820Z', 'BOULON 8*20');
        $this->item($this->setif, '820Z', 'BOULON 8*20');
        $this->item($this->oran, '820-AC', 'ATTACHE CABLE 20CM');
        $this->item($this->setif, '820AC', 'ATTACHE CABLE 20 CM');
        $this->item($this->oran, 'V640', 'VIS 6X40');
        $this->item($this->setif, 'VS-640', 'VIS 6*40');
        $this->item($this->oran, 'BTH820', 'BOULON TH 8*20 ZINGUE');
        $this->item($this->setif, 'B820Z', 'BOULON 8X20 ZING', -2);
        $this->item($this->oran, 'B825', 'BOULON TH 8*25 ZINGUE');
        $this->item($this->oran, 'ROB12', 'ROBINET 1/2 LAITON');
        $this->item($this->setif, 'VAN1521', 'VANNE LAITON 15*21');
    }

    private function connect(string $provider = 'claude', string $model = 'claude-sonnet-5-5'): void
    {
        $row = AiProvider::for($provider);
        $row->api_key = 'sk-test-key-for-merge';
        $row->forceFill(['model' => $model, 'enabled' => true, 'models' => [$model], 'checked_at' => now()])->save();
    }

    /** Reponse de Claude (outil impose) : les numeros d'articles regroupes. */
    private function claude(array $groups): void
    {
        $this->answer = fn () => Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'answer', 'input' => ['groups' => $groups]]]]);
    }

    /** Les numeros que le lot envoye a donnes a ces codes (lus dans le message). */
    private function numbers(array $request, array $codes): array
    {
        $prompt = $request['messages'][0]['content'];
        $out = [];
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/#(\d+) \| [A-Z,]+ \| [^|]*\b'.preg_quote($code, '/').'\b/', $prompt, $code);
            preg_match('/#(\d+) \| [A-Z,]+ \| [^|]*\b'.preg_quote($code, '/').'\b/', $prompt, $m);
            $out[] = (int) $m[1];
        }

        return $out;
    }

    public function test_candidates_found_here_by_code_name_and_likeness_never_a_different_size(): void
    {
        $this->catalog();
        $found = MergeCandidates::find([$this->oran->id, $this->setif->id], true);

        $clusters = collect($found['verify'])->map(fn ($c) => ['source' => $c['source'], 'codes' => collect($c['units'])->flatten()->sort()->values()->all()]);
        $this->assertContains(['source' => 'code', 'codes' => ['820-AC', '820AC']], $clusters->all());
        $this->assertContains(['source' => 'name', 'codes' => ['V640', 'VS-640']], $clusters->all());
        $this->assertContains(['source' => 'similar', 'codes' => ['B820Z', 'BTH820']], $clusters->all());
        $this->assertFalse($clusters->contains(fn ($c) => in_array('B825', $c['codes'], true)), 'une autre mesure');
        $this->assertFalse($clusters->contains(fn ($c) => in_array('820Z', $c['codes'], true)), 'present partout : rien a fusionner');
        $this->assertSame(9, $found['units']);

        // le robinet et la vanne : seulement pour l'analyse approfondie
        $deep = collect($found['discover'])->flatten()->all();
        $this->assertContains('ROB12', $deep);
        $this->assertContains('VAN1521', $deep);
        $this->assertSame([], MergeCandidates::find([$this->oran->id, $this->setif->id], false)['discover']);

        // deja fusionnes, ou declares differents : plus proposes
        ProductGroup::merge(['820-AC', '820AC'], null);
        MergeDismissal::remember(['V640', 'VS-640'], null);
        $codes = collect(MergeCandidates::find([$this->oran->id, $this->setif->id], false)['verify'])->pluck('units')->flatten()->all();
        $this->assertNotContains('820AC', $codes);
        $this->assertNotContains('V640', $codes);

        $this->assertSame('BOULON 8*20 ZING', MergeCandidates::normName('Boulon 8 x 20 zing.'));
        $this->assertSame(['1.5', '8', '20'], MergeCandidates::numbers('TUBE 08*20 1.5'));
    }

    public function test_a_scan_asks_the_ai_by_batch_and_keeps_only_sound_groups(): void
    {
        $this->catalog();
        $this->connect();
        $dev = User::factory()->admin()->create();

        $this->actingAs($dev)->post('/merge/ai', ['deep' => '1'])->assertRedirect('/merge/ai');
        $scan = MergeScan::firstOrFail();
        $this->assertSame(['running', 2], [$scan->status, $scan->batches], 'un lot de candidats, un lot approfondi');
        $this->actingAs($dev)->get('/merge/ai')->assertOk()->assertSee(__('merge_ai.running'))->assertSee(route('merge.ai.step', $scan));

        // lot 1 : les candidats ; l'IA garde le code et le boulon, invente un numero, et met deux articles de la meme branche ensemble
        $this->answer = function (Request $r) {
            $n = $this->numbers($r->data(), ['820-AC', '820AC', 'BTH820', 'B820Z', 'V640']);

            return Http::response(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'answer', 'input' => ['groups' => [
                ['items' => [$n[0], $n[1]], 'confidence' => 'high', 'name' => 'ATTACHE CABLE 20 CM', 'reason' => 'نفس الكود بدون الشرطة.'],
                ['items' => [$n[2], $n[3], 999], 'confidence' => 'medium', 'name' => 'BOULON TH 8*20 ZINGUE', 'reason' => 'نفس المقاس.'],
                ['items' => [$n[4], $n[2]], 'confidence' => 'low', 'name' => 'X', 'reason' => 'meme branche'],
            ]]]]]);
        };
        $this->actingAs($dev)->postJson(route('merge.ai.step', $scan))->assertOk()->assertJson(['status' => 'running', 'done' => 1, 'batches' => 2, 'found' => 2]);

        // ce qui est parti : codes, designations, lettres de branche ; jamais un prix, une quantite ni un nom de branche
        $request = $this->sent[0];
        $this->assertSame('claude-sonnet-5-5', $request['model']);
        $this->assertSame(['type' => 'tool', 'name' => 'answer'], $request['tool_choice']);
        $prompt = json_encode($request, JSON_UNESCAPED_UNICODE);
        foreach (['1234', 'Oran', 'Setif', '-2'] as $secret) {
            $this->assertStringNotContainsString($secret, $prompt, $secret);
        }
        $this->assertStringContainsString('ATTACHE CABLE 20CM', $prompt);
        $this->assertStringContainsString('Arabic', $request['system']);

        // lot 2 (approfondi) : le robinet et la vanne
        $this->answer = function (Request $r) {
            $n = $this->numbers($r->data(), ['ROB12', 'VAN1521']);

            return Http::response(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'answer', 'input' => ['groups' => [
                ['items' => $n, 'confidence' => 'low', 'name' => 'ROBINET 1/2 LAITON', 'reason' => '1/2 = 15*21.'],
            ]]]]]);
        };
        $this->actingAs($dev)->postJson(route('merge.ai.step', $scan))->assertOk()->assertJson(['status' => 'done', 'found' => 3]);
        $this->assertSame(0, ProductGroup::count(), 'rien sans un clic');

        $sources = MergeSuggestion::orderBy('id')->get()->map(fn ($s) => [$s->source, $s->confidence, $s->codes()])->all();
        $this->assertSame([['code', 'high', ['820-AC', '820AC']], ['similar', 'medium', ['B820Z', 'BTH820']], ['discover', 'low', ['ROB12', 'VAN1521']]], $sources);

        // le rapport : les plus surs d'abord, chaque code dans sa branche avec son stock
        $this->actingAs($dev)->get('/merge/ai')->assertOk()
            ->assertSeeInOrder(['ATTACHE CABLE 20 CM', 'BOULON TH 8*20 ZINGUE', 'ROBINET 1/2 LAITON'])
            ->assertSee('نفس الكود بدون الشرطة.')->assertSee(__('merge_ai.confidence.high'))->assertSee(__('merge_ai.source.discover'))
            ->assertSee('Oran')->assertSee('Setif')->assertSee('-2,00');
        $this->actingAs($dev)->get('/merge/ai?c=low')->assertOk()->assertSee('ROBINET 1/2 LAITON')->assertDontSee('ATTACHE CABLE 20 CM');
    }

    public function test_the_report_leaves_every_choice_to_the_person(): void
    {
        $this->catalog();
        $this->item($this->oran, 'GA10', 'GAINE ICTA 16');
        $this->item($this->setif, 'GA-10', 'GAINE ICTA 16');
        $this->connect();
        $dev = User::factory()->admin()->create();
        $scan = MergeScan::create(['user_id' => $dev->id, 'provider' => 'claude', 'model' => 'm', 'locale' => 'ar', 'status' => 'done',
            'branch_ids' => [$this->oran->id, $this->setif->id], 'units' => 9, 'batches' => 1]);
        $s = fn (array $units, string $name) => $scan->suggestions()->create(['units' => $units, 'confidence' => 'high', 'source' => 'code', 'name' => $name]);
        $attache = $s([['820-AC'], ['820AC']], 'ATTACHE');
        $vis = $s([['V640'], ['VS-640']], 'VIS');
        $boulon = $s([['BTH820'], ['B820Z']], 'BOULON');
        $gaine = $s([['GA10'], ['GA-10']], 'GAINE');

        // cocher deux groupes, et dans le boulon decocher un code : le boulon n'est pas fusionne
        $this->actingAs($dev)->from('/merge/ai')->post(route('merge.ai.merge', $scan), [
            'groups' => [$attache->id, $boulon->id],
            'codes' => [$attache->id => ['820-AC', '820AC'], $boulon->id => ['BTH820'], $vis->id => ['V640', 'VS-640']],
        ])->assertRedirect('/merge/ai')->assertSessionHas('status');
        $this->assertSame(['820-AC', '820AC'], ProductGroup::codesOf('820AC'));
        $this->assertSame(['BTH820'], ProductGroup::codesOf('BTH820'));
        $this->assertSame(['V640'], ProductGroup::codesOf('V640'), 'pas coche');
        $this->assertSame(['merged', 'open', 'open'], [$attache->fresh()->status, $boulon->fresh()->status, $vis->fresh()->status]);

        // un groupe a la fois (only), sans pouvoir y glisser un code etranger
        $this->actingAs($dev)->post(route('merge.ai.merge', $scan), ['only' => $vis->id, 'codes' => [$vis->id => ['V640', 'VS-640', '820Z']]]);
        $this->assertSame(['V640', 'VS-640'], ProductGroup::codesOf('V640'));

        // "ليسا نفس الصنف" : range, jamais repropose ; "اعادة" le remet
        $this->actingAs($dev)->post(route('merge.ai.dismiss', [$scan, $gaine]))->assertSessionHas('status');
        $this->assertSame('dismissed', $gaine->fresh()->status);
        $this->assertTrue(MergeDismissal::where('pair', 'GA-10|GA10')->exists());
        $this->assertNotContains('GA10', collect(MergeCandidates::find([$this->oran->id, $this->setif->id], false)['verify'])->pluck('units')->flatten()->all());
        $this->actingAs($dev)->get('/merge/ai?s=dismissed')->assertSee('GAINE')->assertDontSee('>VIS<', false);
        $this->actingAs($dev)->post(route('merge.ai.reopen', [$scan, $gaine]));
        $this->assertSame(['open', 0], [$gaine->fresh()->status, MergeDismissal::count()]);

        // rien coche
        $this->actingAs($dev)->post(route('merge.ai.merge', $scan), [])->assertSessionHasErrors('groups');
    }

    public function test_a_refused_key_stops_the_scan_and_it_resumes_where_it_stopped(): void
    {
        $this->catalog();
        $this->connect('openai', 'gpt-test');
        $dev = User::factory()->admin()->create();
        $this->actingAs($dev)->post('/merge/ai');
        $scan = MergeScan::firstOrFail();

        $this->answer = fn () => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401);
        $this->actingAs($dev)->postJson(route('merge.ai.step', $scan))->assertJson(['status' => 'failed']);
        $this->actingAs($dev)->get('/merge/ai')->assertSee(__('ai.errors.auth', ['name' => 'ChatGPT']))->assertSee(route('merge.ai.resume', $scan));

        // OpenAI : schema strict, la reponse dans le message
        $this->assertSame('json_schema', $this->sent[0]['response_format']['type']);
        $this->assertTrue($this->sent[0]['response_format']['json_schema']['strict']);

        $this->actingAs($dev)->post(route('merge.ai.resume', $scan));
        $this->answer = fn () => Http::response(['choices' => [['message' => ['content' => '{"groups": []}']]]]);
        // la tache planifiee finit le travail quand la page est fermee
        MergeScanner::runPending(5);
        $this->assertSame(['done', 1, 0], [$scan->fresh()->status, $scan->fresh()->batches_done, MergeSuggestion::count()]);
    }

    public function test_an_unreadable_answer_is_retried_then_skipped(): void
    {
        $this->catalog();
        $this->connect('gemini', 'gemini-test');
        $dev = User::factory()->admin()->create();
        $this->actingAs($dev)->post('/merge/ai');
        $scan = MergeScan::firstOrFail();

        $this->answer = fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Sorry, I cannot.']]]]]]);
        foreach (range(1, 3) as $i) {
            $this->actingAs($dev)->postJson(route('merge.ai.step', $scan));
        }
        $scan->refresh();
        $this->assertSame(['done', 0, 1], [$scan->status, $scan->batches_done, $scan->batches_failed]);
        $this->assertStringContainsString('gemini-test:generateContent', Http::recorded()[0][0]->url());
        $this->assertTrue(Http::recorded()[0][0]->hasHeader('x-goog-api-key', 'sk-test-key-for-merge'));
        $this->actingAs($dev)->get('/merge/ai')->assertSee(trans_choice('merge_ai.batches_failed', 1, ['count' => 1]));
    }

    public function test_who_may_scan_and_see_a_scan(): void
    {
        $this->catalog();
        $dev = User::factory()->admin()->create();

        // sans IA connectee : on le dit, rien ne part
        $this->actingAs($dev)->get('/merge/ai')->assertOk()->assertSee(__('merge_ai.no_provider'));
        $this->actingAs($dev)->post('/merge/ai')->assertSessionHasErrors('provider');
        $this->assertSame(0, MergeScan::count());

        // sans merge.manage : ni la page ni le lancement
        $plain = User::factory()->inBranch($this->oran)->create();
        $this->actingAs($plain)->get('/merge/ai')->assertForbidden();
        $this->actingAs($plain)->post('/merge/ai')->assertForbidden();

        // un compte qui ne voit qu'Oran ne voit pas l'analyse d'Oran + Setif, ni ne la fait avancer ; il ne peut pas en lancer (une seule branche)
        $this->connect();
        $this->actingAs($dev)->post('/merge/ai');
        $scan = MergeScan::firstOrFail();
        $karim = User::factory()->inBranch($this->oran)->withPermissions(['merge.manage'])->create();
        $this->actingAs($karim)->get('/merge/ai')->assertOk()->assertDontSee('Setif')->assertSee(__('merge_ai.need_two'));
        $this->actingAs($karim)->postJson(route('merge.ai.step', $scan))->assertNotFound();
        $this->actingAs($karim)->post(route('merge.ai.cancel', $scan))->assertNotFound();
        $this->actingAs($karim)->post('/merge/ai')->assertSessionHasErrors('provider');

        // la page "دمج الأصناف" mene aux propositions
        $this->actingAs($dev)->get('/merge')->assertSee(route('merge.ai'));
    }
}
