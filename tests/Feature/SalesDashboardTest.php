<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "لوحة القيادة" (DashboardController, SalesDashboard) : chiffres de la periode et tendance, courbe, plus vendus,
 * clients, vendus puis epuises, stock dormant ; seulement les branches dont le compte voit les ventes, et rien
 * de ce que ses permissions de prix ou de stock ne lui montrent pas.
 */
class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: Branch} */
    private function register(string $name, string $version = '1.0.7'): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => $version,
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    private function stock(Branch $branch, string $code, string $designation, float $stock, ?float $buy = null): void
    {
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => BranchStock::keyFor($code), 'designation' => $designation,
            'stock' => $stock, 'price_buy' => $buy, 'seen_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, list<array>>  $days */
    private function sales(string $token, array $days, string $dataset = 'sales'): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $days])->assertOk();
    }

    private function bon(int $number, string $time, string $partner, array $lines): array
    {
        return ['key' => (string) $number, 'digest' => $number, 'number' => $number, 'partner' => $partner, 'time' => $time,
            'amount' => array_sum(array_column($lines, 'total')), 'lines' => $lines];
    }

    /**
     * Oran, "aujourd'hui" = 04/11/2026 a midi. Aujourd'hui 183 (4 boulons) et 184 (2 tubes, un article rendu) ;
     * hier 180 a 8 h et 181 a 18 h 30 ; il y a 40 jours 170 ; il y a 100 jours la derniere vente du robinet ; un achat.
     */
    private function oran(): array
    {
        [$token, $oran] = $this->register('Oran');
        $this->stock($oran, '820Z', 'BOULON 8*20', 0);
        $this->stock($oran, 'BTH', 'TUBE CUIVRE 14', 5, 400);
        $this->stock($oran, 'OLD1', 'ROBINET LAITON 1/2', 12, 50);
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali'],
        ]]])->assertOk();
        $this->sales($token, [
            '2026-07-27' => [$this->bon(160, '10:00:00', 'C001', [['code' => 'OLD1', 'qty' => 1, 'price' => 100, 'total' => 100, 'cost' => 50, 'position' => 1]])],
            '2026-09-25' => [$this->bon(170, '10:00:00', 'C001', [['code' => '820Z', 'qty' => 1, 'price' => 7777, 'total' => 7777, 'position' => 1]])],
            '2026-11-03' => [
                $this->bon(180, '08:00:00', 'C001', [['code' => 'BTH', 'qty' => 1, 'price' => 500, 'total' => 500, 'cost' => 400, 'position' => 1]]),
                $this->bon(181, '18:30:00', 'C001', [['code' => '820Z', 'qty' => 30, 'price' => 300, 'total' => 9000, 'cost' => 250, 'position' => 1]]),
            ],
            '2026-11-04' => [
                $this->bon(183, '09:18:33', 'C999', [['code' => '820z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 250, 'position' => 1]]),
                $this->bon(184, '10:05:00', 'C001', [
                    ['code' => 'BTH', 'qty' => 2, 'price' => 550, 'total' => 1100, 'cost' => 400, 'position' => 1],
                    ['code' => 'GONE1', 'qty' => -1, 'price' => 550, 'total' => -550, 'position' => 2],
                ]),
            ],
        ]);
        $this->sales($token, ['2026-11-02' => [['key' => '40|F01', 'digest' => 5, 'number' => 40, 'partner' => 'F01', 'amount' => 12025, 'lines' => [
            ['code' => 'BTH', 'qty' => 50, 'price' => 240.5, 'total' => 12025],
        ]]]], 'purchases');

        return [$token, $oran];
    }

    public function test_the_period_its_trend_best_sellers_customers_and_what_needs_attention(): void
    {
        [, $oran] = $this->oran();
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();

        // 30 jours : 1 200 + 550 + 500 + 9 000 ; avant, le bon 170 (7 777) : 45 % de plus
        $html = $this->actingAs($boss)->get('/dashboard')->assertOk()->getContent();
        foreach (['11 250', __('dashboard.up', ['pct' => '45']), __('dashboard.compare.30'),
            'BOULON 8*20', '10 200', 'TUBE CUIVRE 14', 'Ets Benali', '10 050', 'C999',
            __('dashboard.margin'), '2 100', __('dashboard.margin_pct', ['pct' => "\u{2066}17,8\u{2069}"]), __('dashboard.margin_partial'),
            '12 025', trans_choice('documents.return_lines', 1, ['count' => 1]),
            __('dashboard.sold_out_title'), 'ROBINET LAITON 1/2', trans_choice('dashboard.dormant_count', 1, ['count' => "\u{2066}1\u{2069}"]),
            __('dashboard.dormant_value', ['value' => "\u{2066}600\u{2069}"])] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('7 777', $html);
        // les plus vendus : le boulon d'abord, son stock a 0 en rouge (couleur de Gescomm) ; le lien mene a sa page
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*?class="qty zero">0</s', $html);
        $this->assertStringContainsString(route('stock.item', ['code' => '820Z']), $html);
        // vendu puis epuise : le boulon (34 vendus, 0 en stock), pas le tube (5 en stock)
        $soldOut = substr($html, strpos($html, e(__('dashboard.sold_out_title'))), 2000);
        $this->assertStringContainsString('BOULON 8*20', $soldOut);
        $this->assertStringContainsString('>34<', $soldOut);
        $this->assertStringNotContainsString('TUBE CUIVRE 14', substr($soldOut, 0, strpos($soldOut, e(__('dashboard.dormant_title')))));

        // aujourd'hui, compare a hier jusqu'a midi (le bon de 8 h, pas celui de 18 h 30) : 1 750 contre 500
        $html = $this->actingAs($boss)->get('/dashboard?period=today')->assertOk()->getContent();
        foreach (['1 750', __('dashboard.up', ['pct' => '250']), __('dashboard.compare.today'), __('dashboard.chart.amount.hour')] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('11 250', $html);

        // par quantite : le boulon (34) puis le tube (3)
        $html = $this->actingAs($boss)->get('/dashboard?rank=qty')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*TUBE CUIVRE 14/s', $html);

        // 12 mois : une barre par mois
        $html = $this->actingAs($boss)->get('/dashboard?period=12m')->assertOk()->getContent();
        $bars = substr($html, strpos($html, 'class="bars"'), strpos($html, 'class="axis"') - strpos($html, 'class="bars"'));
        $this->assertSame(12, substr_count($bars, '<span '));
    }

    public function test_a_day_or_days_chosen_in_the_calendar(): void
    {
        [, $oran] = $this->oran();
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // hier (le 03/11) : 500 + 9 000, heure par heure, rien la veille pour comparer ; la veille et le lendemain (aujourd'hui)
        $html = $this->actingAs($boss)->get('/dashboard?day=2026-11-03')->assertOk()->getContent();
        $label = Carbon::parse('2026-11-03')->locale('ar')->translatedFormat('l').' 03/11';
        foreach (['9 500', __('dashboard.no_compare'), __('dashboard.chart.amount.hour'), $label,
            route('dashboard.index', ['day' => '2026-11-02']), route('dashboard.index', ['period' => 'today']),
            route('dashboard.index', ['day' => '2026-11-03', 'rank' => 'qty'])] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('11 250', $html);
        // de 7 h a 18 h (la derniere vente) : la courbe d'un jour passe s'arrete a sa derniere vente
        $bars = substr($html, strpos($html, 'class="bars"'), strpos($html, 'class="axis"') - strpos($html, 'class="bars"'));
        $this->assertSame(12, substr_count($bars, '<span '));

        // aujourd'hui choisi = "اليوم", avec sa comparaison a la meme heure ; un jour a venir = 30 jours
        $this->actingAs($boss)->get('/dashboard?day=2026-11-04')->assertOk()->assertSee('1 750')->assertSee(__('dashboard.compare.today'));
        $this->actingAs($boss)->get('/dashboard?day=2026-12-01')->assertOk()->assertSee('11 250');

        // des jours (dans n'importe quel ordre) : 7 777 le 25/09 + 9 500 le 03/11, un jour par barre
        $html = $this->actingAs($boss)->get('/dashboard?from=2026-11-03&to=2026-09-25')->assertOk()->getContent();
        foreach (['17 277', '25/09 – 03/11', __('dashboard.chart.amount.day')] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        // plus de deux mois : une barre par mois, le mois de depart compris
        $html = $this->actingAs($boss)->get('/dashboard?from=2026-01-15&to=2026-11-04')->assertOk()->getContent();
        $bars = substr($html, strpos($html, 'class="bars"'), strpos($html, 'class="axis"') - strpos($html, 'class="bars"'));
        $this->assertSame(11, substr_count($bars, '<span '));
    }

    public function test_the_calendar_gives_each_day_of_a_month(): void
    {
        [, $oran] = $this->oran();
        [$setifToken] = $this->register('Setif');
        $this->sales($setifToken, ['2026-11-03' => [$this->bon(70777, '11:00:00', 'C001', [['code' => 'SET9', 'qty' => 3, 'price' => 3333, 'total' => 9999, 'position' => 1]])]]);
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // novembre 2026 : le 1er est un dimanche (la semaine commence le samedi), aujourd'hui le 4, la suite a venir
        $json = $this->actingAs($boss)->getJson('/dashboard/calendar?month=2026-11')->assertOk()
            ->assertJson(['month' => '2026-11', 'today' => '2026-11-04', 'offset' => 1, 'prev' => '2026-10', 'next' => null, 'money' => true, 'cash' => false]);
        $days = collect($json->json('days'))->keyBy('date');
        $this->assertCount(30, $days);
        $this->assertEquals([9500, 2], [$days['2026-11-03']['amount'], $days['2026-11-03']['bons']]);
        $this->assertEquals([1750, 2], [$days['2026-11-04']['amount'], $days['2026-11-04']['bons']]);
        $this->assertSame([false, true], [$days['2026-11-04']['future'], $days['2026-11-05']['future']]);
        $this->assertSame(Carbon::parse('2026-11-03')->locale('ar')->translatedFormat('l j F Y'), $days['2026-11-03']['label']);
        // Setif : une branche dont il voit le stock, pas les ventes
        $this->assertStringNotContainsString('9999', $json->getContent());

        // pas avant le mois de la premiere vente (juillet), pas apres ce mois-ci
        $this->actingAs($boss)->getJson('/dashboard/calendar?month=2026-07')->assertOk()->assertJson(['prev' => null, 'next' => '2026-08']);
        $this->actingAs($boss)->getJson('/dashboard/calendar?month=2027-01')->assertOk()->assertJson(['month' => '2026-11']);
        $this->actingAs($boss)->getJson('/dashboard/calendar?month=nope')->assertOk()->assertJson(['month' => '2026-11']);

        // sans "أسعار البيع" : les bons, jamais les montants
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $json = $this->actingAs($karim)->getJson('/dashboard/calendar?month=2026-11')->assertOk()->assertJson(['money' => false]);
        $this->assertNull($json->json('days.2.amount'));
        $this->assertSame(2, $json->json('days.2.bons'));
        $this->assertStringNotContainsString('9500', $json->getContent());

        // sans "المبيعات" : rien
        $this->actingAs(User::factory()->inBranch($oran)->withPermissions([])->create())->getJson('/dashboard/calendar')->assertForbidden();
    }

    public function test_without_price_permissions_it_counts_bons_and_quantities_only(): void
    {
        [, $oran] = $this->oran();
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();

        $html = $this->actingAs($karim)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('dashboard.chart.bons.day')), $html);
        $this->assertStringContainsString('BOULON 8*20', $html);
        $this->assertStringContainsString('Ets Benali', $html);
        foreach (['11 250', '10 200', '10 050', '12 025', '2 100', __('dashboard.dormant_value', ['value' => "\u{2066}600\u{2069}"]), __('dashboard.margin'), __('dashboard.purchases'), __('dashboard.ranks.amount')] as $text) {
            $this->assertStringNotContainsString(e($text), $html, $text);
        }

        // sans sales.view : ni l'application, ni la page
        $nobody = User::factory()->inBranch($oran)->withPermissions([])->create();
        $this->actingAs($nobody)->get('/dashboard')->assertForbidden();
        $this->actingAs($nobody)->get('/')->assertOk()->assertDontSee(route('dashboard.index'));
        $this->actingAs($karim)->get('/')->assertOk()->assertSee(route('dashboard.index'));
    }

    public function test_merged_codes_are_one_best_seller(): void
    {
        [$token, $oran] = $this->oran();
        // un autre jour : un jour envoye remplace tous les bons de ce jour
        $this->sales($token, ['2026-11-01' => [$this->bon(185, '11:00:00', 'C001', [['code' => '820-Z', 'qty' => 100, 'price' => 1, 'total' => 100, 'position' => 1]])]]);
        ProductGroup::merge(['820Z', '820-Z'], null);
        Cache::flush();
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();

        $html = $this->actingAs($boss)->get('/dashboard?rank=qty')->assertOk()->getContent();
        // 34 + 100 sur une seule ligne, sous le premier code du groupe
        $this->assertStringContainsString('>134<', $html);
        $this->assertStringNotContainsString('>100<', $html);
    }

    public function test_a_user_of_a_never_sees_the_sales_of_b_and_the_owner_sees_each_branch(): void
    {
        [, $oran] = $this->oran();
        [$setifToken, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida', '1.0.6');
        $this->withToken($setifToken)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Setif'],
        ]]])->assertOk();
        $this->sales($setifToken, ['2026-11-04' => [$this->bon(70777, '11:00:00', 'C001', [['code' => 'SET9', 'qty' => 3, 'price' => 3333, 'total' => 9999, 'position' => 1]])]]);

        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->actingAs($karim)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('11 250', $html);
        foreach (['Ets Setif', '9 999', 'SET9', '21 249'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
        }
        $this->assertStringNotContainsString(e(__('dashboard.branches_help.amount')), $html);
        // Setif choisi en haut (il en voit le stock, pas les ventes) : rien de Setif
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => $setif->id]);
        $this->actingAs($karim)->get('/dashboard')->assertOk()->assertDontSee('9 999')->assertDontSee('11 250')
            ->assertSee(__('documents.no_branch_selected', ['branch' => 'Setif']));
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => '']);

        // le directeur de la societe : le total, la part de chaque branche ; Blida (agent 1.0.6) est nommee, pas comptee
        $owner = User::factory()->owner()->withPermissions(['sales.view', 'prices.sale'])->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);
        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();
        foreach (['21 249', 'Ets Setif', 'Ets Benali', e(__('dashboard.branches_help.amount')), e(__('documents.outdated', ['names' => 'Blida']))] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('--bh: '.$setif->hue(), $html);
    }
}
