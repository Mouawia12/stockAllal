<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Exercise;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Scopes\ExerciseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cloture de Gescomm (App\Models\Exercise) : l'agent 1.0.11 envoie la marque de la base qu'il lit (le jour de ses
 * "Solde Initial" et l'exercice qu'ils reportent). Des "Solde Initial" plus recents = Gescomm a cloture : l'exercice
 * en cours se ferme sans rien perdre, un nouveau commence. Les bases archivees (LaBaseN<nom>.ib) arrivent chacune dans
 * leur exercice clos ; les pages montrent l'exercice en cours, un exercice clos s'ouvre a la demande.
 */
class ExerciseSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        ExerciseScope::$viewing = 0;
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => 'Artisana', 'hostname' => 'PC-A', 'agent_version' => '1.0.11',
        ])->assertCreated();
        $this->token = $res->json('token');
        $this->branch = Branch::findOrFail($res->json('branch_id'));
    }

    protected function tearDown(): void
    {
        ExerciseScope::$viewing = 0;
        parent::tearDown();
    }

    private function mark(?string $openedOn, ?string $label, ?string $firstDay = null): array
    {
        return ['opened_on' => $openedOn, 'label' => $label, 'first_day' => $firstDay ?? $openedOn];
    }

    /** Une vente (un bon d'une ligne) par jour donne : jour => [numero, montant]. */
    private function sales(array $days): array
    {
        $groups = [];
        foreach ($days as $day => [$number, $amount]) {
            $groups[$day] = [['key' => (string) $number, 'digest' => $number, 'number' => $number, 'partner' => 'C001', 'amount' => $amount,
                'lines' => [['code' => '820Z', 'qty' => 1, 'price' => $amount, 'total' => $amount]]]];
        }

        return $groups;
    }

    private function digests(array $groups): array
    {
        return array_map(fn (array $records) => count($records).':'.array_sum(array_column($records, 'digest')), $groups);
    }

    /** Une etape de l'agent : digest puis records des groupes demandes ; $extra = exercise (marque) ou exercise_id. */
    private function step(string $set, ?string $from, ?string $to, array $groups, array $extra): array
    {
        $send = $this->withToken($this->token)->postJson('/api/agent/digest', ['dataset' => $set, 'from' => $from, 'to' => $to,
            'groups' => $this->digests($groups)] + $extra)->assertOk()->json('send');
        if ($send) {
            $this->withToken($this->token)->postJson('/api/agent/records', ['dataset' => $set,
                'groups' => array_combine($send, array_map(fn ($g) => $groups[$g] ?? [], $send))] + $extra)->assertOk();
        }

        return $send;
    }

    private function openings(string $day, string $label): array
    {
        return [$day => [['key' => $day.'|1', 'digest' => 1, 'number' => 1, 'partner' => 'C001', 'type' => 'Autre', 'amount' => -500,
            'note' => "Solde Initial Client de l'exercice $label"]]];
    }

    public function test_a_closing_in_gescomm_closes_the_exercise_here_without_losing_anything(): void
    {
        $old = ['exercise' => $this->mark('2026-06-22', '20263')];
        $this->step('sales', '2026-06-01', '2026-11-30', $this->sales(['2026-07-01' => [10, 1000], '2026-11-02' => [11, 2000]]), $old);
        $this->step('customer_payments', '2026-06-01', '2026-11-30', $this->openings('2026-06-22', '20263'), $old);
        $current = Exercise::where('branch_id', $this->branch->id)->where('current', true)->sole();
        $this->assertSame(['2026-06-22', '20263'], [$current->opened_on->toDateString(), $current->opening_label]);
        $this->assertSame(2, Document::count());

        // Gescomm cloture le 10/11 sous le nom 20264 : la base est videe, un "Solde Initial" du jour reporte le solde
        $new = ['exercise' => $this->mark('2026-11-10', '20264')];
        $send = $this->step('sales', '2026-11-04', '2026-11-10', $this->sales(['2026-11-10' => [1, 300]]), $new);
        $this->assertSame(['2026-11-10'], $send, 'the old days are not the current exercise any more: nothing to remove');
        $this->step('customer_payments', '2026-11-04', '2026-11-10', $this->openings('2026-11-10', '20264'), $new);

        $closed = Exercise::where('branch_id', $this->branch->id)->where('current', false)->sole();
        $this->assertSame('20264', $closed->name, 'the new base carries over "the exercise 20264": the one that closed');
        $this->assertSame(['2026-06-22', '2026-11-02'], [$closed->first_day->toDateString(), $closed->last_day->toDateString()]);
        $this->assertNotNull($closed->closed_at);
        // en cours : le bon 1 et son "Solde Initial" ; l'exercice clos garde ses deux bons, leurs lignes et son versement
        $this->assertSame([1], Document::pluck('number')->all());
        $this->assertSame(2, Document::withoutGlobalScope(ExerciseScope::class)->where('exercise_id', $closed->id)->count());
        $this->assertSame(2, DocumentLine::withoutGlobalScope(ExerciseScope::class)->where('exercise_id', $closed->id)->count());
        $this->assertSame(1, Payment::withoutGlobalScope(ExerciseScope::class)->where('exercise_id', $closed->id)->count());
        $this->assertSame(1, Payment::count());

        // la meme marque ensuite : rien ne bouge
        $this->step('sales', '2026-11-04', '2026-11-10', $this->sales(['2026-11-10' => [1, 300]]), $new);
        $this->assertSame(2, Exercise::where('branch_id', $this->branch->id)->count());
    }

    public function test_each_archived_base_goes_into_its_own_closed_exercise(): void
    {
        $this->step('sales', '2026-11-01', '2026-11-30', $this->sales(['2026-11-02' => [5, 100]]), ['exercise' => $this->mark('2026-06-22', '20263')]);
        $archive = fn (array $extra) => $this->withToken($this->token)->postJson('/api/agent/archive', $extra + [
            'file' => 'D:\\Gescomm\\LaBaseN20263.ib', 'opened_on' => '2026-02-07', 'label' => '20262',
            'first_day' => '2026-02-07', 'last_day' => '2026-06-22', 'counts' => ['sales' => 2, 'customer_payments' => 1],
        ]);

        $id = $archive([])->assertOk()->assertJson(['name' => '20263'])->json('exercise_id');
        $this->assertSame($id, $archive([])->json('exercise_id'), 'the same base twice is the same exercise');
        $groups = $this->sales(['2026-03-01' => [5, 700], '2026-06-20' => [6, 900]]);
        $this->assertSame(['2026-06-20', '2026-03-01'], $this->step('sales', '2026-02-01', '2026-06-30', $groups, ['exercise_id' => $id]));
        $this->assertSame([], $this->step('sales', '2026-02-01', '2026-06-30', $groups, ['exercise_id' => $id]));

        // bon numero 5 dans les deux exercices : chacun le sien ; les pages ne montrent que celui en cours
        $this->assertSame([100.0], Document::pluck('amount')->all());
        ExerciseScope::$viewing = $id;
        $this->assertSame([700.0, 900.0], Document::orderBy('date')->pluck('amount')->all());
        ExerciseScope::$viewing = 0;
        $this->withToken($this->token)->postJson('/api/agent/archive', ['file' => 'LaBaseN20263.ib', 'done' => true, 'exercise_id' => $id])->assertOk();
        $this->assertNotNull(Exercise::find($id)->synced_at);

        // une base vide (LABASENV.IB), une copie de la base en cours : rien a lire
        $this->withToken($this->token)->postJson('/api/agent/archive', ['file' => 'LABASENV.IB', 'counts' => []])->assertOk()->assertJson(['skip' => 'empty']);
        $archive(['opened_on' => '2026-06-22', 'label' => '20263'])->assertOk()->assertJson(['skip' => 'current']);
        // un exercice qui n'est pas d'ici, ou celui en cours : refuse
        $this->withToken($this->token)->postJson('/api/agent/digest', ['dataset' => 'sales', 'from' => '2026-02-01', 'to' => '2026-02-28',
            'groups' => [], 'exercise_id' => $id + 99])->assertStatus(422)->assertJson(['error' => 'invalid_exercise']);
        $this->withToken($this->token)->postJson('/api/agent/digest', ['dataset' => 'customers', 'groups' => ['p01' => '1:1'],
            'exercise_id' => $id])->assertStatus(422);
    }

    public function test_an_archive_waits_for_the_current_mark_and_a_base_without_openings_is_known_by_its_file(): void
    {
        $archive = ['file' => 'LaBaseN20261.ib', 'opened_on' => null, 'label' => null, 'first_day' => '2025-10-09', 'last_day' => '2026-01-19',
            'counts' => ['sales' => 437]];
        // un agent d'avant 1.0.11 (pas de marque) : la base en cours n'est pas encore connue
        $this->step('sales', '2026-11-01', '2026-11-30', $this->sales(['2026-11-02' => [5, 100]]), []);
        $this->withToken($this->token)->postJson('/api/agent/archive', $archive)->assertOk()->assertJson(['skip' => 'wait']);

        $this->step('sales', '2026-11-01', '2026-11-30', $this->sales(['2026-11-02' => [5, 100]]), ['exercise' => $this->mark('2026-06-22', '20263')]);
        $id = $this->withToken($this->token)->postJson('/api/agent/archive', $archive)->assertOk()->json('exercise_id');
        $this->assertSame($id, $this->withToken($this->token)->postJson('/api/agent/archive', $archive)->json('exercise_id'));
        $this->assertSame('20261', Exercise::find($id)->name);
    }

    public function test_a_closing_made_before_the_agent_was_updated_is_seen_at_its_first_mark(): void
    {
        // agent ancien : l'exercice d'avant (60 bons de juillet a septembre), puis la cloture du 10/11 sans marque
        $days = [];
        for ($i = 0; $i < 60; $i++) {
            $days[date('Y-m-d', strtotime("2026-07-01 +$i days"))] = [100 + $i, 50];
        }
        $this->step('sales', '2026-07-01', '2026-09-30', $this->sales($days), []);
        $this->step('sales', '2026-11-10', '2026-11-10', $this->sales(['2026-11-10' => [1, 300]]), []);
        $this->assertSame(61, Document::count());

        // l'agent mis a jour envoie sa marque : 60 bons d'avant ses "Solde Initial" = une cloture deja faite ; l'exercice
        // en cours se ferme avec tout ce qu'il avait, le bon du 10/11 arrive aussitot dans le nouveau (la base archivee,
        // envoyee ensuite, retirera de l'exercice clos ce qui n'y etait pas)
        $this->step('sales', '2026-11-10', '2026-11-10', $this->sales(['2026-11-10' => [1, 300]]), ['exercise' => $this->mark('2026-11-10', '20264')]);
        $closed = Exercise::where('branch_id', $this->branch->id)->where('current', false)->sole();
        $this->assertSame(61, Document::withoutGlobalScope(ExerciseScope::class)->where('exercise_id', $closed->id)->count());
        $this->assertSame([1], Document::pluck('number')->all());
    }

    public function test_the_menu_opens_a_closed_exercise_on_every_page_then_goes_back(): void
    {
        $this->step('sales', '2026-11-01', '2026-11-30', $this->sales(['2026-11-02' => [5, 100]]), ['exercise' => $this->mark('2026-06-22', '20263')]);
        $this->withToken($this->token)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali']]]])->assertOk();
        $id = $this->withToken($this->token)->postJson('/api/agent/archive', ['file' => 'LaBaseN20263.ib', 'opened_on' => '2026-02-07', 'label' => '20262',
            'first_day' => '2026-02-07', 'last_day' => '2026-06-22', 'counts' => ['sales' => 1]])->json('exercise_id');
        $this->step('sales', '2026-03-01', '2026-03-31', $this->sales(['2026-03-01' => [7, 700]]), ['exercise_id' => $id]);
        $user = User::factory()->inBranch($this->branch)->withPermissions(['sales.view', 'prices.sale'])->create();
        $benali = route('customers.show', Partner::where('code', 'C001')->sole());

        // l'exercice en cours : le menu propose 20263, la fiche donne le solde d'aujourd'hui
        $this->actingAs($user)->get('/sales?period=all')->assertOk()->assertSee('100,00')->assertDontSee('700,00')
            ->assertSee('value="'.$id.'"', false)->assertDontSee(__('exercises.back'));
        $this->actingAs($user)->get($benali)->assertSee('<span class="wb-value">100,00</span>', false);

        // 20263 ouvert : ses bons partout, la banniere, le solde a sa cloture
        $this->actingAs($user)->from('/sales')->post(route('exercise'), ['exercise_id' => $id])->assertRedirect('/sales');
        $this->actingAs($user)->get('/sales?period=all')->assertOk()->assertSee('700,00')->assertDontSee('>100,00<', false)
            ->assertSee(__('exercises.back'))->assertSee('07/02/2026 → 22/06/2026', false);
        $this->actingAs($user)->get($benali)->assertSee('<span class="wb-value">700,00</span>', false)
            ->assertSee(__('exercises.balance_closed', ['name' => '20263']));

        // retour a l'exercice en cours
        $this->actingAs($user)->post(route('exercise'), ['exercise_id' => null])->assertSessionMissing('exercise');
        $page = $this->actingAs($user)->get('/sales?period=all');
        $page->assertDontSee(__('exercises.back'));
        $page->assertSee('100,00');

        // un compte qui ne voit pas cette branche : ni le menu, ni l'exercice
        $other = Branch::create(['name' => 'Autre', 'active' => true]);
        $stranger = User::factory()->inBranch($other)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($stranger)->post(route('exercise'), ['exercise_id' => $id])->assertNotFound();
    }

    public function test_a_bon_of_a_closed_exercise_opens_by_its_link(): void
    {
        $this->step('sales', '2026-11-01', '2026-11-30', $this->sales(['2026-11-02' => [5, 100]]), ['exercise' => $this->mark('2026-06-22', '20263')]);
        $id = $this->withToken($this->token)->postJson('/api/agent/archive', ['file' => 'LaBaseN20263.ib', 'opened_on' => '2026-02-07', 'label' => '20262',
            'first_day' => '2026-02-07', 'last_day' => '2026-06-22', 'counts' => ['sales' => 1]])->json('exercise_id');
        $this->step('sales', '2026-03-01', '2026-03-31', $this->sales(['2026-03-01' => [7, 700]]), ['exercise_id' => $id]);
        $old = Document::withoutGlobalScope(ExerciseScope::class)->where('exercise_id', $id)->sole();

        $user = User::factory()->inBranch($this->branch)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($user)->get(route('sales.show', $old))->assertOk()->assertSee('700,00');
        $this->actingAs($user)->get('/sales?period=all')->assertOk()->assertDontSee('700,00');
    }
}
