<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\BranchTask;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Support\DatasetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ventes, achats, clients, fournisseurs et versements envoyes par l'agent 1.0.7 (agent/README.md) :
 * empreintes par groupe, puis seulement les groupes qui different ; un envoi rejoue ou coupe ne casse rien.
 */
class AgentDataTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function register(string $name = 'Oran'): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => '1.0.7',
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    /** Un bon de vente comme l'agent l'envoie ; l'empreinte change avec son contenu. */
    private function sale(int $number, array $lines, string $partner = 'C001', ?int $digest = null): array
    {
        return [
            'key' => (string) $number, 'digest' => $digest ?? crc32(json_encode([$number, $lines, $partner])),
            'number' => $number, 'time' => '09:18:33.0000', 'partner' => $partner, 'amount' => array_sum(array_column($lines, 'total')),
            'payment' => 'Espéce', 'entered_by' => 'ADMIN',
            'lines' => array_map(fn (array $l, int $i) => $l + ['position' => $i + 1], $lines, array_keys($lines)),
        ];
    }

    /** "nombre:somme" d'un groupe, comme l'agent le calcule */
    private function digestOf(array $records): string
    {
        return count($records).':'.array_sum(array_column($records, 'digest'));
    }

    private function digest(string $token, string $dataset, array $groups, ?string $from = null, ?string $to = null): TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/digest', ['dataset' => $dataset, 'from' => $from, 'to' => $to,
            'groups' => array_map(fn (array $records) => $this->digestOf($records), $groups)]);
    }

    private function records(string $token, string $dataset, array $groups): TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $groups]);
    }

    public function test_only_differing_days_are_asked_and_a_replayed_page_changes_nothing(): void
    {
        [$token, $branch] = $this->register();
        $days = [
            '2026-11-03' => [$this->sale(182, [['code' => '820B', 'qty' => -25, 'price' => 300, 'total' => -7500], ['code' => '820Z', 'qty' => 10, 'price' => 310, 'total' => 3100]])],
            '2026-11-04' => [$this->sale(183, [['code' => '820Z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 250]])],
        ];

        // rien sur le serveur : il demande les deux jours, le plus recent d'abord
        $this->digest($token, 'sales', $days, '2026-10-29', '2026-11-04')->assertOk()
            ->assertJson(['send' => ['2026-11-04', '2026-11-03'], 'page_rows' => DatasetSync::PAGE_ROWS]);
        $this->records($token, 'sales', $days)->assertOk()->assertJson(['written' => 2, 'removed' => 0]);

        $bon = Document::where('number', 182)->sole();
        $this->assertSame(['sale', '2026-11-03', '09:18:33', 'C001'], [$bon->kind, $bon->date->toDateString(), $bon->time, $bon->partner_code]);
        $this->assertEquals(-4400, $bon->amount);
        $this->assertSame(3, DocumentLine::count());
        $this->assertEquals(-25, DocumentLine::where('code_key', '820B')->value('qty'), 'a return is a negative line');

        // meme contenu : plus rien a envoyer ; page rejouee (coupure apres envoi) : rien n'est reecrit
        $this->digest($token, 'sales', $days, '2026-10-29', '2026-11-04')->assertJson(['send' => []]);
        $before = Document::orderBy('id')->pluck('updated_at', 'id');
        $this->travel(1)->minutes();
        $this->records($token, 'sales', $days)->assertJson(['written' => 0, 'removed' => 0]);
        $this->assertEquals($before, Document::orderBy('id')->pluck('updated_at', 'id'));
        $this->assertNotNull(BranchTask::where('branch_id', $branch->id)->where('task', 'sales')->value('last_ok_at'));

        // un bon modifie dans Gescomm : seul son jour est demande, seul lui est reecrit, ses lignes remplacees
        $days['2026-11-04'] = [$this->sale(183, [['code' => '820Z', 'qty' => 6, 'price' => 300, 'total' => 1800]])];
        $this->digest($token, 'sales', $days, '2026-10-29', '2026-11-04')->assertJson(['send' => ['2026-11-04']]);
        $this->records($token, 'sales', ['2026-11-04' => $days['2026-11-04']])->assertJson(['written' => 1, 'removed' => 0]);
        $this->assertEquals(6, DocumentLine::where('document_id', Document::where('number', 183)->value('id'))->sole()->qty);
    }

    public function test_deleted_bons_and_emptied_days_are_removed_and_a_moved_bon_is_moved(): void
    {
        [$token] = $this->register();
        $a = $this->sale(10, [['code' => 'A1', 'qty' => 1, 'total' => 100]]);
        $b = $this->sale(11, [['code' => 'A1', 'qty' => 2, 'total' => 200]]);
        $c = $this->sale(12, [['code' => 'B2', 'qty' => 3, 'total' => 300]]);
        $this->records($token, 'sales', ['2026-11-01' => [$a, $b], '2026-11-02' => [$c]])->assertOk();

        // le bon 11 supprime dans Gescomm, et tout le 2 novembre aussi : le serveur redemande les deux jours
        $this->digest($token, 'sales', ['2026-11-01' => [$a]], '2026-11-01', '2026-11-04')
            ->assertJson(['send' => ['2026-11-02', '2026-11-01']]);
        $this->records($token, 'sales', ['2026-11-01' => [$a], '2026-11-02' => []])->assertJson(['written' => 0, 'removed' => 2]);
        $this->assertSame([10], Document::pluck('number')->all());
        $this->assertSame(1, DocumentLine::count(), 'lines of removed bons go too');

        // la date du bon 10 changee dans Gescomm : il passe au 3 novembre, une seule fois
        $this->records($token, 'sales', ['2026-11-03' => [$a]])->assertJson(['written' => 1]);
        $this->records($token, 'sales', ['2026-11-01' => []])->assertJson(['removed' => 0]);
        $this->assertSame(['2026-11-03'], Document::pluck('date')->map->toDateString()->all());
        $this->assertSame('2026-11-03', DocumentLine::sole()->date->toDateString());
    }

    public function test_the_range_limits_what_is_compared(): void
    {
        [$token] = $this->register();
        $old = $this->sale(1, [['code' => 'A1', 'qty' => 1, 'total' => 100]]);
        $this->records($token, 'sales', ['2026-06-23' => [$old]])->assertOk();

        // les 7 derniers jours : le jour de juin n'est ni redemande ni efface
        $this->digest($token, 'sales', [], '2026-10-29', '2026-11-04')->assertJson(['send' => []]);
        $this->digest($token, 'sales', ['2026-06-23' => [$old]], '2026-10-29', '2026-11-04')->assertJson(['send' => []]);
        $this->assertSame(1, Document::count());
        // la verification de la nuit, mois par mois, le voit
        $this->digest($token, 'sales', [], '2026-06-01', '2026-06-30')->assertJson(['send' => ['2026-06-23']]);
    }

    public function test_each_branch_and_each_dataset_only_touches_its_own_rows(): void
    {
        [$oran] = $this->register('Oran');
        [$setif] = $this->register('Setif');
        $sale = $this->sale(5, [['code' => 'A1', 'qty' => 1, 'total' => 100]]);
        $this->records($oran, 'sales', ['2026-11-04' => [$sale]])->assertOk();
        $this->records($oran, 'purchases', ['2026-11-04' => [['key' => '5|F01', 'digest' => 7, 'number' => 5, 'partner' => 'F01',
            'lines' => [['code' => 'A1', 'qty' => 50, 'price' => 80, 'total' => 4000]]]]])->assertOk();

        // le bon 5 de Setif n'est pas celui d'Oran ; un achat n'est pas une vente
        $this->records($setif, 'sales', ['2026-11-04' => []])->assertJson(['removed' => 0]);
        $this->records($oran, 'sales', ['2026-11-04' => [$sale]])->assertJson(['written' => 0]);
        $this->assertSame(['purchase', 'sale'], Document::orderBy('kind')->pluck('kind')->all());
        $this->assertSame([50.0, 1.0], DocumentLine::orderBy('kind')->pluck('qty')->all());
    }

    public function test_customers_suppliers_and_payments(): void
    {
        [$token, $branch] = $this->register();
        $customers = ['p07' => [['key' => 'C001', 'digest' => 11, 'code' => 'C001', 'name' => '  Ets Benali ', 'phone' => '0550', 'opening_balance' => '1500.50', 'blocked' => 'Non']]];
        $this->digest($token, 'customers', $customers)->assertJson(['send' => ['p07']]);
        $this->records($token, 'customers', $customers)->assertOk();
        $this->records($token, 'suppliers', ['p01' => [['key' => 'F01', 'digest' => 12, 'name' => '', 'name_alt' => 'TPL', 'blocked' => 'Oui']]])->assertOk();
        $this->records($token, 'customer_payments', ['2026-11-04' => [['key' => '2026-11-04|7', 'digest' => 13, 'number' => 7,
            'partner' => 'C001', 'type' => 'Autre', 'amount' => -624.5, 'paid_on' => '2026-11-05']]])->assertOk();

        $c = Partner::where('kind', 'customer')->sole();
        $this->assertSame(['C001', 'Ets Benali', 1500.5, false], [$c->code, $c->name, $c->opening_balance, $c->blocked]);
        $supplier = Partner::where('kind', 'supplier')->sole();
        $this->assertSame(['F01', 'TPL', true], [$supplier->code, $supplier->name, $supplier->blocked], 'empty MONFOURNISSEUR: the short column');
        $p = Payment::sole();
        $this->assertSame(['customer', 'C001', 'Autre', -624.5, '2026-11-05'], [$p->partner_kind, $p->partner_code, $p->type, $p->amount, $p->paid_on->toDateString()]);

        // pas un seul client dans Gescomm = lecture ratee : refuse, rien n'est efface
        $this->digest($token, 'customers', [])->assertUnprocessable();
        $this->assertSame(1, Partner::where('kind', 'customer')->count());
        $this->assertSame($branch->id, $c->branch_id);
    }

    public function test_bad_requests_are_refused_and_agent_errors_are_recorded(): void
    {
        [$token, $branch] = $this->register();
        $this->withToken($token)->postJson('/api/agent/digest', ['dataset' => 'invoices', 'groups' => []])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/agent/digest', ['dataset' => 'sales', 'from' => '04/11/2026', 'groups' => []])->assertUnprocessable();
        $this->records($token, 'sales', ['hier' => []])->assertUnprocessable();
        $this->records($token, 'sales', [])->assertUnprocessable();
        $big = ['key' => '1', 'digest' => 1, 'lines' => array_fill(0, DatasetSync::MAX_ROWS, ['code' => 'A1', 'qty' => 1])];
        $this->records($token, 'sales', ['2026-11-04' => [$big]])->assertUnprocessable()->assertJson(['error' => 'too_many_rows']);
        $this->withoutToken()->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => ['2026-11-04' => []]])->assertUnauthorized();
        $this->assertSame(0, Document::count());

        // la lecture de GC010 a echoue dans la branche : note sur la page des branches, les autres taches continuent
        $this->withToken($token)->postJson('/api/agent/digest', ['dataset' => 'sales', 'error' => 'db_error', 'message' => 'isql : table GC010 inconnue'])
            ->assertOk()->assertJson(['send' => []]);
        $task = BranchTask::where('branch_id', $branch->id)->where('task', 'sales')->sole();
        $this->assertTrue($task->failing());
        $this->assertStringContainsString('GC010', $task->last_error);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/branches')->assertOk()->assertSee(__('branches.tasks.sales'));

        // la tache repart : plus en erreur
        $this->records($token, 'sales', ['2026-11-04' => []])->assertOk();
        $this->assertFalse($task->fresh()->failing());
    }

    public function test_article_levels_family_and_unit_come_with_the_stock(): void
    {
        [$token] = $this->register();
        $item = ['code' => '820Z', 'designation' => 'BOULON', 'stock' => 3, 'price' => 300, 'stock_alert' => 5, 'stock_max' => 100, 'family' => 'VIS', 'unit' => 'PCS'];
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [$item]])->assertOk()->assertJson(['changed' => 1]);
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [$item]])->assertJson(['changed' => 0]);
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [['stock_alert' => 2] + $item]])->assertJson(['changed' => 1]);

        $row = BranchStock::sole();
        $this->assertSame([2.0, 100.0, 'VIS', 'PCS'], [$row->stock_alert, $row->stock_max, $row->family, $row->unit]);
        $admin = User::factory()->admin()->create();
        // 3 au-dessus du seuil 2 : rien ; seuil 5 : vert, comme dans Gescomm (la legende, elle, est toujours la)
        $low = '/qty low"\s+title/';
        $this->assertDoesNotMatchRegularExpression($low, $this->actingAs($admin)->get('/stock/item?code=820Z')->assertOk()->getContent());
        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [$item]])->assertOk();
        $this->assertMatchesRegularExpression($low, $this->actingAs($admin)->get('/stock/item?code=820Z')->getContent());
    }
}
