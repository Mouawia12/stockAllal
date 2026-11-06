<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\User;
use App\Support\PartnerInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "علاقته معنا" (App\Support\PartnerInsights) : la note de fidelite et ce qui la compose, ce qu'on compare a qui, et ce qu'un
 * compte sans prices.* ne voit pas. Le 15/11/2026 : la periode va du 01/12/2025 a ce jour.
 */
class PartnerInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: Branch} */
    private function branch(string $name): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => '1.0.8',
        ])->assertCreated();
        $branch = Branch::findOrFail($res->json('branch_id'));
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => '820Z', 'code_key' => '820Z', 'designation' => 'BOULON 8*20', 'stock' => 5, 'seen_at' => now(), 'updated_at' => now()]);

        return [$res->json('token'), $branch];
    }

    private function send(string $token, string $dataset, array $groups): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $groups])->assertOk();
    }

    /**
     * Regulier : un bon par mois sur les 12 mois (le 15, 1000 chacun, marge 200), + un bon le 15/11/2025 pour l'an dernier (6000).
     * Recent : 3 bons de 500 en novembre, le dernier il y a 5 jours. Ancien : un bon de 400 il y a 200 jours.
     * Dormant : son dernier bon date de deux ans. Un autre client dans une autre branche.
     */
    private function history(string $token): void
    {
        $this->send($token, 'customers', ['p1' => [
            ['key' => 'R', 'digest' => 1, 'code' => 'R', 'name' => 'Ets Regulier'],
            ['key' => 'N', 'digest' => 2, 'code' => 'N', 'name' => 'Sarl Recent'],
            ['key' => 'A', 'digest' => 3, 'code' => 'A', 'name' => 'Ets Ancien'],
            ['key' => 'D', 'digest' => 4, 'code' => 'D', 'name' => 'Sarl Dormant'],
            ['key' => 'V', 'digest' => 5, 'code' => 'V', 'name' => 'Ets Vide'],
        ]]);
        $groups = [];
        $add = function (string $day, string $partner, float $amount, int $number) use (&$groups) {
            $groups[$day][] = ['key' => (string) $number, 'digest' => $number, 'number' => $number, 'partner' => $partner, 'amount' => $amount, 'time' => '10:15:00.0000',
                'lines' => [['code' => '820Z', 'qty' => 1, 'price' => $amount, 'total' => $amount, 'cost' => $amount * .8]]];
        };
        $n = 100;
        for ($i = 0; $i < 12; $i++) {
            $add(Carbon::parse('2026-11-15')->subMonthsNoOverflow($i)->toDateString(), 'R', 1000, $n++);
        }
        $add('2025-11-15', 'R', 6000, $n++);
        $add('2026-11-02', 'N', 500, $n++);
        $add('2026-11-06', 'N', 500, $n++);
        $add('2026-11-10', 'N', 500, $n++);
        $add(now()->subDays(200)->toDateString(), 'A', 400, $n++);
        $add('2024-11-01', 'D', 900, $n++);
        $this->send($token, 'sales', $groups);
        $this->send($token, 'customer_payments', [
            '2026-11-01' => [['key' => 'p|1', 'digest' => 1, 'number' => 1, 'partner' => 'R', 'type' => 'Espéce', 'amount' => 9000]],
            '2026-11-12' => [['key' => 'p|2', 'digest' => 2, 'number' => 2, 'partner' => 'R', 'type' => 'Espéce', 'amount' => 1800]],
        ]);
    }

    private function partner(string $code): Partner
    {
        return Partner::where('code', $code)->firstOrFail();
    }

    public function test_the_loyalty_score_and_where_it_stands_among_the_others_of_the_branch(): void
    {
        [$token] = $this->branch('Oran');
        $this->history($token);
        // un client d'une autre branche, tres regulier : il ne change rien a la comparaison d'Oran
        [$other] = $this->branch('Setif');
        $this->send($other, 'customers', ['p1' => [['key' => 'Z', 'digest' => 1, 'code' => 'Z', 'name' => 'Client de Setif']]]);
        $this->send($other, 'sales', ['2026-11-14' => [['key' => '1', 'digest' => 1, 'number' => 1, 'partner' => 'Z', 'amount' => 99999, 'lines' => []]]]);

        // 12 mois sur 12 = 50 ; 12 bons, plus que 2 sur 3 et lui-meme a moitie = 25 ; dernier bon aujourd'hui = 20
        $r = PartnerInsights::for($this->partner('R'), true, true);
        $this->assertSame(95, $r['loyalty']['score']);
        $this->assertSame('high', $r['loyalty']['band']);
        $this->assertSame(100, $r['loyalty']['beats']);
        $this->assertSame(2, $r['loyalty']['compared']);
        $this->assertSame(12, $r['active_months']);
        $this->assertSame(100, $r['loyalty']['regular']);
        $this->assertSame(83, $r['loyalty']['frequent']);
        $this->assertSame(100, $r['loyalty']['recent']);

        // 1 mois sur 12 = 4,17 ; 3 bons, plus qu'un sur 3 et lui-meme a moitie = 15 ; 5 jours = 20
        $n = PartnerInsights::for($this->partner('N'), true, true);
        $this->assertSame(39, $n['loyalty']['score']);
        $this->assertSame('occasional', $n['loyalty']['band']);
        $this->assertSame(50, $n['loyalty']['beats']);

        // 1 mois = 4,17 ; 1 bon, le moins de tous = 5 ; 200 jours = 0
        $a = PartnerInsights::for($this->partner('A'), true, true);
        $this->assertSame(9, $a['loyalty']['score']);
        $this->assertSame('rare', $a['loyalty']['band']);
        $this->assertSame(0, $a['loyalty']['beats']);

        // l'histogramme : un client dans chaque tranche, aucun nom
        $this->assertSame([1, 0, 0, 1, 0, 0, 0, 0, 0, 1], $r['loyalty']['hist']);
        $this->assertSame(9, $r['loyalty']['bucket']);
        $this->assertSame(3, $n['loyalty']['bucket']);
    }

    public function test_what_the_figures_say_about_a_customer(): void
    {
        [$token] = $this->branch('Oran');
        $this->history($token);
        $r = PartnerInsights::for($this->partner('R'), true, true);

        $this->assertTrue($r['active']);
        $this->assertSame('2025-11-15', $r['first'], 'le premier bon, meme avant la periode');
        $this->assertFalse($r['is_new']);
        $this->assertSame(12, $r['bons']);
        $this->assertSame(12000.0, $r['amount']);
        // 12 000 contre 6 000 sur les memes dates un an plus tot
        $this->assertSame(100, $r['trend']['pct']);

        $this->assertCount(12, $r['months']);
        $this->assertSame('2025-12', $r['months'][0]['key']);
        $this->assertSame('2026-11', $r['months'][11]['key']);
        $this->assertTrue($r['months'][11]['now']);
        $this->assertSame(1000.0, $r['months'][5]['amount']);

        // 12 000 sur 13 900 dans la branche, premier des trois
        $this->assertSame(['by' => 'amount', 'place' => 1, 'of' => 3, 'top' => 34, 'share' => 86.3], $r['rank']);
        // un mois d'ecart d'habitude, dernier bon aujourd'hui
        $this->assertSame('on_time', $r['rhythm']['state']);
        $this->assertSame(31, $r['rhythm']['gap']);
        // 1000 le bon ; la branche 13 900 / 16 bons = 868,75
        $this->assertSame(1000.0, $r['average']['amount']);
        $this->assertSame(868.75, $r['average']['branch']);
        $this->assertSame(15, $r['average']['pct']);
        // 10 800 pour 12 000
        $this->assertSame(10800.0, $r['paid']['amount']);
        $this->assertSame(90, $r['paid']['pct']);
        $this->assertSame('12/11/2026', $r['paid']['last_date']);
        // 20 % de 12 000
        $this->assertSame(2400.0, $r['margin']['amount']);
        $this->assertSame(20.0, $r['margin']['pct']);
        $this->assertFalse($r['margin']['partial']);

        $this->assertSame('820Z', $r['items'][0]['code']);
        $this->assertSame('BOULON 8*20', $r['items'][0]['designation']);
        $this->assertSame(100.0, $r['items'][0]['share']);
        $this->assertSame(1, $r['breadth']);

        $this->assertCount(7, $r['week']);
        $this->assertSame('10', $r['hour']);
        // aujourd'hui, le 15/11/2026, est un dimanche : deuxieme jour d'une semaine qui commence le samedi
        $this->assertSame(Carbon::parse('2026-11-15')->dayOfWeek, 0);

        // un dernier bon loin de son habitude
        $n = PartnerInsights::for($this->partner('N'), true, true, now()->addDays(40));
        $this->assertContains($n['rhythm']['state'], ['late', 'away']);
    }

    public function test_a_customer_without_a_bon_in_the_year_is_named_as_such(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        $d = PartnerInsights::for($this->partner('D'), true, true);
        $this->assertFalse($d['active']);
        $this->assertNull($d['loyalty']);
        $html = $this->actingAs($karim)->get(route('customers.show', $this->partner('D')))->assertOk()->getContent();
        $this->assertStringContainsString(__('partners.insights.none.customer'), $html);
        $this->assertStringContainsString('01/11/2024', $html);

        $html = $this->actingAs($karim)->get(route('customers.show', $this->partner('V')))->assertOk()->getContent();
        $this->assertStringContainsString(__('partners.insights.none_ever'), $html);
    }

    public function test_the_card_never_names_another_customer_nor_shows_amounts_without_prices(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $this->history($token);
        $regular = $this->partner('R');

        $share = __('partners.insights.share.customer', ['pct' => "\u{2066}86\u{2069}"]);
        $owner = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        $html = $this->actingAs($owner)->get(route('customers.show', $regular))->assertOk()->getContent();
        foreach ([__('partners.insights.score.customer'), __('partners.insights.bands.customer.high'), $share, '2 400', __('partners.insights.margin'), __('partners.insights.paid.customer')] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['Sarl Recent', 'Ets Ancien', 'Sarl Dormant', 'Ets Vide'] as $other) {
            $this->assertStringNotContainsString($other, $html, $other);
        }

        // prices.sale seul : pas la marge
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->actingAs($seller)->get(route('customers.show', $regular))->assertOk()->getContent();
        $this->assertStringNotContainsString(__('partners.insights.margin'), $html);
        $this->assertStringContainsString(__('partners.insights.paid.customer'), $html);

        // sales.view seul : la meme note (elle ne compte pas l'argent), et ni montant, ni versement, ni marge
        $plain = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->actingAs($plain)->get(route('customers.show', $regular))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="fig">95</span>', $html);
        foreach ([__('partners.insights.margin'), __('partners.insights.paid.customer'), __('partners.insights.average'), '12 000', '2 400', '10 800', '9 000', $share] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        $this->assertStringContainsString(__('partners.insights.share_bons', ['pct' => "\u{2066}75\u{2069}"]), $html, '12 bons sur 16');

        $i = PartnerInsights::for($regular, false, false);
        $this->assertSame(95, $i['loyalty']['score']);
        $this->assertSame('bons', $i['rank']['by']);
        $this->assertNull($i['amount']);
        $this->assertNull($i['average']);
        $this->assertNull($i['paid']);
        $this->assertNull($i['margin']);
        $this->assertNull($i['months'][0]['amount']);
        $this->assertNull($i['items'][0]['amount']);
    }

    public function test_a_supplier_card_speaks_of_dealings_and_has_no_margin(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $this->send($token, 'suppliers', ['p2' => [['key' => 'F01', 'digest' => 1, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $this->send($token, 'purchases', ['2026-11-10' => [['key' => '40|F01', 'digest' => 2, 'number' => 40, 'partner' => 'F01', 'amount' => 12025,
            'lines' => [['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025]]]]]);
        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.buy', 'prices.sale'])->create();

        $html = $this->actingAs($buyer)->get(route('suppliers.show', $this->partner('F01')))->assertOk()->getContent();
        $this->assertStringContainsString(__('partners.insights.title.supplier'), $html);
        $this->assertStringContainsString(__('partners.insights.score.supplier'), $html);
        $this->assertStringNotContainsString(__('partners.insights.margin'), $html);
    }
}
