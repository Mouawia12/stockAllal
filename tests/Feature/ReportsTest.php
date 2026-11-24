<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "التقارير" de "لوحة القيادة" (ReportController, App\Support\Reports) : chaque rapport en entier, trie, filtre, cherche,
 * imprime et exporte ; seulement les branches dont le compte voit les ventes, le stock de celles dont il voit le stock,
 * et rien de ce que ses permissions de prix ne lui montrent pas (ni a l'ecran, ni par un tri, ni dans l'export).
 */
class ReportsTest extends TestCase
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
    private function register(string $name, string $version = '1.0.8'): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => $version,
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    private function stock(Branch $branch, string $code, string $designation, float $stock, ?float $buy = null, ?float $price = null, ?float $alert = null): void
    {
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => BranchStock::keyFor($code), 'designation' => $designation,
            'stock' => $stock, 'price_buy' => $buy, 'price' => $price, 'stock_alert' => $alert, 'seen_at' => now(), 'updated_at' => now()]);
    }

    private function send(string $token, string $dataset, array $groups): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $groups])->assertOk();
    }

    private function bon(int $number, string $time, string $partner, array $lines, ?string $by = null): array
    {
        return ['key' => (string) $number, 'digest' => $number, 'number' => $number, 'partner' => $partner, 'time' => $time, 'entered_by' => $by,
            'amount' => array_sum(array_column($lines, 'total')), 'lines' => $lines];
    }

    /**
     * Oran, "aujourd'hui" = 04/11/2026 a midi. Sur 30 jours : le boulon (34 vendus, stock 0), le tube (3 vendus, stock 5
     * sous son seuil de 10), un article rendu. Le robinet : derniere vente il y a 100 jours. La vanne : achetee, jamais
     * vendue. Le coude : stock negatif. Benali a paye 4 000 et porte un report de 500 ; un achat chez Fournil, paye 5 000.
     *
     * @return array{0: string, 1: Branch}
     */
    private function oran(): array
    {
        [$token, $oran] = $this->register('Oran');
        $this->stock($oran, '820Z', 'BOULON 8*20', 0, 250, 300);
        $this->stock($oran, 'BTH', 'TUBE CUIVRE 14', 5, 400, 550, 10);
        $this->stock($oran, 'OLD1', 'ROBINET LAITON 1/2', 12, 50, 100);
        $this->stock($oran, 'NEW9', 'VANNE NEUVE', 3, 1000, 1500);
        $this->stock($oran, 'NEG1', 'COUDE PVC', -2, 10, 20);
        $this->send($token, 'customers', ['p01' => [['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali']]]);
        $this->send($token, 'suppliers', ['p01' => [['key' => 'F01', 'digest' => 2, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $this->send($token, 'sales', [
            '2026-07-27' => [$this->bon(160, '10:00:00', 'C001', [['code' => 'OLD1', 'qty' => 1, 'price' => 100, 'total' => 100, 'cost' => 50, 'position' => 1]])],
            '2026-09-25' => [$this->bon(170, '10:00:00', 'C001', [['code' => '820Z', 'qty' => 1, 'price' => 7777, 'total' => 7777, 'position' => 1]])],
            '2026-11-03' => [
                $this->bon(180, '08:00:00', 'C001', [['code' => 'BTH', 'qty' => 1, 'price' => 500, 'total' => 500, 'cost' => 400, 'position' => 1]], 'KARIM'),
                $this->bon(181, '18:30:00', 'C001', [['code' => '820Z', 'qty' => 30, 'price' => 300, 'total' => 9000, 'cost' => 250, 'position' => 1]], 'KARIM'),
            ],
            '2026-11-04' => [
                $this->bon(183, '09:18:33', 'C999', [['code' => '820z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 250, 'position' => 1]], 'ADMIN'),
                $this->bon(184, '10:05:00', 'C001', [
                    ['code' => 'BTH', 'qty' => 2, 'price' => 550, 'total' => 1100, 'cost' => 400, 'position' => 1],
                    ['code' => 'GONE1', 'qty' => -1, 'price' => 550, 'total' => -550, 'position' => 2],
                ], 'ADMIN'),
            ],
        ]);
        $this->send($token, 'purchases', ['2026-11-02' => [['key' => '40|F01', 'digest' => 5, 'number' => 40, 'partner' => 'F01', 'amount' => 15025, 'lines' => [
            ['code' => 'BTH', 'qty' => 50, 'price' => 240.5, 'total' => 12025, 'position' => 1],
            ['code' => 'NEW9', 'qty' => 3, 'price' => 1000, 'total' => 3000, 'position' => 2],
        ]]]]);
        $this->send($token, 'customer_payments', [
            '2026-10-10' => [['key' => 'v|1', 'digest' => 6, 'number' => 1, 'partner' => 'C001', 'type' => 'Autre', 'amount' => -500, 'note' => "Solde Initial Client de l'exercice 2025"]],
            '2026-11-03' => [['key' => 'v|2', 'digest' => 7, 'number' => 2, 'partner' => 'C001', 'type' => 'Espéce', 'amount' => 4000]],
        ]);
        $this->send($token, 'supplier_payments', ['2026-11-03' => [['key' => 'w|1', 'digest' => 8, 'number' => 1, 'partner' => 'F01', 'type' => 'Espéce', 'amount' => 5000]]]);

        return [$token, $oran];
    }

    /** Le tableau du rapport, sans le reste de la page (menus, styles, barre Ctrl+K). */
    private function table(string $html): string
    {
        $start = strpos($html, '<table class="list rep">');
        $this->assertNotFalse($start, 'the report has a table');

        return substr($html, $start, strpos($html, '</table>', $start) - $start);
    }

    private function boss(Branch $branch): User
    {
        return User::factory()->inBranch($branch)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
    }

    public function test_every_item_sold_with_its_share_class_margin_and_stock(): void
    {
        [, $oran] = $this->oran();
        $boss = $this->boss($oran);

        // sans rapport dans l'adresse : le premier, avec la periode demandee
        $this->actingAs($boss)->get('/dashboard/reports?period=7')->assertRedirect(route('dashboard.reports', ['report' => 'products', 'period' => '7']));

        $html = $this->actingAs($boss)->get('/dashboard/reports/products')->assertOk()->getContent();
        // les chiffres : 3 articles, 11 250 de ventes, 2 100 de marge (17,8 %), 1 rendu, 1 article fait 80 % des ventes
        foreach ([__('reports.titles.products'), '>11 250<', '>2 100<', __('reports.notes.margin_pct', ['v' => "\u{2066}17,8\u{2069}"]),
            __('reports.notes.class_a', ['v' => "\u{2066}33,3\u{2069}"]), __('reports.groups.sales'), __('reports.tabs.days')] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $table = $this->table($html);
        // le boulon d'abord (10 200, classe A, 86,4 % des ventes, stock 0 en rouge), le tube (B), puis l'article rendu (C)
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*class="abc a".*>34<.*>10 200<.*86,4%.*>1 700<.*16,7%.*class="qty zero">0<.*TUBE CUIVRE 14.*class="abc b".*>1 600<.*GONE1.*class="abc c".*-550/s', $table);
        $this->assertStringContainsString(route('stock.item', ['code' => '820Z']), $table);
        $this->assertStringNotContainsString('7 777', $html);
        // le total de la liste : 37 sortis, 1 rendu, 11 250, 2 100 de marge
        $foot = substr($table, strpos($table, '<tfoot>'));
        foreach (['>37<', '>11 250<', '>2 100<', '17,8%'] as $text) {
            $this->assertStringContainsString($text, $foot, $text);
        }

        // un clic sur un titre trie : par quantite nette croissante, l'article rendu d'abord
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/products?sort=net&dir=asc')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/GONE1.*TUBE CUIVRE 14.*BOULON 8\*20/s', $table);
        $this->assertStringContainsString('aria-sort="ascending"', $table);

        // un filtre et la recherche (chaque mot, dans n'importe quel ordre), seule avec ?partial=1
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/products?f=returned')->assertOk()->getContent());
        $this->assertStringContainsString('GONE1', $table);
        $this->assertStringNotContainsString('BOULON', $table);
        $partial = $this->actingAs($boss)->get('/dashboard/reports/products?q=14+tube&partial=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('<html', $partial);
        $this->assertStringContainsString('<mark>TUBE</mark>', $partial);
        $this->assertStringNotContainsString('BOULON', $partial);
        $this->actingAs($boss)->get('/dashboard/reports/products?q=zzz')->assertOk()->assertSee(__('reports.none_search', ['q' => 'zzz']));

        // toute l'annee : le bon de septembre (7 777) et le robinet de juillet y sont
        $html = $this->actingAs($boss)->get('/dashboard/reports/products?period=all')->assertOk()->getContent();
        $this->assertStringContainsString('ROBINET LAITON 1/2', $html);
        $this->assertStringContainsString('>17 977<', $html);
        // un jour choisi : hier, 9 500
        $this->actingAs($boss)->get('/dashboard/reports/products?day=2026-11-03')->assertOk()->assertSee('>9 500<', false)->assertDontSee('>11 250<', false);
    }

    public function test_merged_codes_are_one_item(): void
    {
        [$token, $oran] = $this->oran();
        $this->send($token, 'sales', ['2026-11-01' => [$this->bon(185, '11:00:00', 'C001', [['code' => '820-Z', 'qty' => 100, 'price' => 1, 'total' => 100, 'position' => 1]])]]);
        ProductGroup::merge(['820Z', '820-Z'], null);
        Cache::flush();

        $table = $this->table($this->actingAs($this->boss($oran))->get('/dashboard/reports/products')->assertOk()->getContent());
        $this->assertStringContainsString('>134<', $table);
        $this->assertStringContainsString('>10 300<', $table);
        $this->assertStringNotContainsString('>100<', $table);
    }

    public function test_dormant_stock_out_of_stock_and_stock_value(): void
    {
        [, $oran] = $this->oran();
        $boss = $this->boss($oran);

        // 90 jours : le robinet (100 jours sans vente, 600) et la vanne jamais vendue (3 000) = 64,3 % de la valeur du stock
        $html = $this->actingAs($boss)->get('/dashboard/reports/dormant')->assertOk()->getContent();
        $table = $this->table($html);
        $this->assertMatchesRegularExpression('/VANNE NEUVE.*'.preg_quote(e(__('reports.never')), '/').'.*02\/11\/2026.*>3 000<.*ROBINET LAITON 1\/2.*>100<.*27\/07\/2026.*>600</s', $table);
        $this->assertStringNotContainsString('TUBE CUIVRE', $table);
        foreach (['>3 600<', __('reports.notes.of_stock', ['v' => "\u{2066}64,3\u{2069}"])] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // 180 jours : le robinet n'est plus dormant ; "jamais vendus" : la vanne seule
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/dormant?days=180')->assertOk()->getContent());
        $this->assertStringContainsString('VANNE NEUVE', $table);
        $this->assertStringNotContainsString('ROBINET', $table);
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/dormant?f=never')->assertOk()->getContent());
        $this->assertStringNotContainsString('ROBINET', $table);

        // a racheter : le boulon (34 vendus, stock 0, rouge) et le tube (3 vendus, 5 en stock, au seuil 10 : vert)
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/reorder')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*>34<.*class="qty zero">0<.*TUBE CUIVRE 14.*>3<.*class="qty low">5<.*>10</s', $table);
        $this->assertStringNotContainsString('ROBINET', $table);
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/reorder?f=low')->assertOk()->getContent());
        $this->assertStringNotContainsString('BOULON', $table);

        // la valeur du stock : 5 600 a l'achat, 8 450 au detail ; le negatif a part
        $html = $this->actingAs($boss)->get('/dashboard/reports/stock')->assertOk()->getContent();
        foreach (['>5 600<', '>8 450<', __('reports.notes.over_cost', ['v' => "\u{2066}2 850\u{2069}"])] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $table = $this->table($html);
        $this->assertMatchesRegularExpression('/VANNE NEUVE.*>3 000<.*53,6%.*TUBE CUIVRE 14.*>2 000<.*ROBINET LAITON 1\/2.*>600</s', $table);
        $this->assertStringNotContainsString('COUDE PVC', $table);
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/stock?f=negative')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/COUDE PVC.*class="qty negative">-2</s', $table);
    }

    public function test_the_shortages_say_where_else_the_item_is_in_the_branches_whose_stock_the_account_sees(): void
    {
        [, $oran] = $this->oran();
        [$setifToken, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida', '1.0.6');
        [, $alger] = $this->register('Alger');
        // le boulon manque a Oran : 40 a Setif (ecrit 820z, sous son seuil de 50), 12,5 a Blida sous un code fusionne,
        // 987 a Alger. Le tube (5 a Oran, sous son seuil) : 0 a Setif, donc nulle part.
        $this->stock($setif, '820z', 'BOULON 8X20', 40, 250, 300, 50);
        $this->stock($setif, 'BTH', 'TUBE CUIVRE 14', 0);
        $this->stock($blida, '820-Z', 'BOULON 8-20', 12.5);
        $this->stock($alger, '820Z', 'BOULON ALGER', 987);
        ProductGroup::merge(['820Z', '820-Z'], null);
        $this->send($setifToken, 'sales', ['2026-11-02' => [$this->bon(900, '11:00:00', 'C001', [['code' => '820Z', 'qty' => 6, 'price' => 300, 'total' => 1800, 'position' => 1]])]]);
        $all = [$oran->id, $setif->id, $blida->id, $alger->id];
        // Oran voit le stock de Setif et de Blida, plus celui d'Alger
        BranchAccess::set($oran->id, 'stock', [$setif->id, $blida->id], $all);
        $karim = $this->boss($oran);

        // le boulon : Setif 40 (en vert, sous son seuil) puis Blida 12,5 et son code la-bas ; le tube : nulle part
        $html = $this->actingAs($karim)->get('/dashboard/reports/reorder')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*data-else.*Setif.*class="qty low">40<.*Blida.*class="qty ">12,50<.*<template>.*820-Z.*>52,50<.*TUBE CUIVRE 14/s', $html);
        foreach ([__('reports.cols.elsewhere'), __('reports.tiles.elsewhere'), __('reports.notes.nowhere', ['v' => "\u{2066}1\u{2069}"]), __('reports.else.title'),
            __('reports.else.here', ['branch' => 'Oran', 'stock' => "\u{2066}0\u{2069}", 'sold' => "\u{2066}34\u{2069}"]), __('reports.else.none')] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertSame(1, substr_count($html, 'class="else" data-else'));
        // ni la branche dont il ne voit pas le stock, ni les ventes de Setif (il n'en voit que le stock)
        foreach (['Alger', '987', 'BOULON ALGER', __('reports.else.sold')] as $secret) {
            $this->assertStringNotContainsString(e($secret), $html, $secret);
        }

        // les filtres et le tri par ce qu'il y a ailleurs
        $html = $this->actingAs($karim)->get('/dashboard/reports/reorder?f=elsewhere')->assertOk()->getContent();
        $this->assertStringContainsString('BOULON 8*20', $html);
        $this->assertStringNotContainsString('TUBE CUIVRE', $html);
        $html = $this->actingAs($karim)->get('/dashboard/reports/reorder?f=nowhere')->assertOk()->getContent();
        $this->assertStringContainsString('TUBE CUIVRE', $html);
        $this->assertStringNotContainsString('BOULON 8*20', $html);
        $html = $this->actingAs($karim)->get('/dashboard/reports/reorder?sort=else_qty&dir=asc')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/TUBE CUIVRE 14.*BOULON 8\*20/s', $html);

        // Oran seule choisie en haut : ce sont justement les autres qu'on cherche
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => $oran->id]);
        $this->actingAs($karim)->get('/dashboard/reports/reorder')->assertOk()->assertSee('class="qty low">40<', false);
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => '']);

        // sur papier et dans le fichier pour Excel
        $html = $this->actingAs($karim)->get('/dashboard/reports/reorder/print')->assertOk()->getContent();
        foreach (['Autres succursales', 'Setif 40 · Blida 12,50'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('Alger', $html);
        $csv = $this->actingAs($karim)->withCookie('print', json_encode(['lang' => 'fr']))->get('/dashboard/reports/reorder/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Quantité ailleurs";"Autres succursales"', $csv);
        $this->assertStringContainsString(';52,50;"Setif: 40 | Blida: 12,50";', $csv);
        $this->assertStringNotContainsString('Alger', $csv);

        // le directeur voit aussi les ventes de Setif : elle en a vendu 6 dans la periode ; Blida (agent 1.0.6) : inconnu
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);
        $html = $this->actingAs($owner)->get('/dashboard/reports/reorder?f=zero')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/BOULON 8\*20.*<template>.*'.preg_quote(e(__('reports.else.sold')), '/').'.*Setif.*>40<.*class="num">6<.*Blida.*'
            .preg_quote(e(__('reports.else.sales_unseen')), '/').'/s', $html);
        $this->assertStringNotContainsString('Alger', $html);

        // un compte qui ne voit que le stock de sa branche : ni colonne, ni filtre, ni tri
        BranchAccess::set($oran->id, 'stock', [], $all);
        foreach (['', '?f=elsewhere', '?sort=else_qty&dir=asc'] as $query) {
            $html = $this->actingAs($karim)->get('/dashboard/reports/reorder'.$query)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/BOULON 8\*20.*TUBE CUIVRE 14/s', $this->table($html));
            foreach (['class="else"', 'id="else-bg"', 'Setif', 'Blida', __('reports.cols.elsewhere'), __('reports.filters.elsewhere'), __('reports.tiles.elsewhere')] as $absent) {
                $this->assertStringNotContainsString(e($absent), $html, $query.' '.$absent);
            }
        }
        $csv = $this->actingAs($karim)->get('/dashboard/reports/reorder/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Setif', $csv);
    }

    public function test_customers_suppliers_their_debts_days_and_sellers(): void
    {
        [, $oran] = $this->oran();
        $boss = $this->boss($oran);
        $benali = Partner::where('code', 'C001')->sole();

        // les clients de la periode : Benali (3 bons, 10 050, a paye 4 000, le report d'exercice n'est pas un versement)
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/customers')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/Ets Benali.*>3<.*>10 050<.*89,3%.*>3 350<.*>550<.*>1 900<.*>4 000<.*04\/11\/2026.*C999.*>1 200</s', $table);
        $this->assertStringContainsString(route('customers.show', $benali), $table);

        // ce que chacun doit, comme Gescomm : report 500 + tous ses bons 17 927 - 4 000 verses = 14 427
        $html = $this->actingAs($boss)->get('/dashboard/reports/receivable')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Ets Benali.*>500,00<.*>17 927,00<.*>4 000,00<.*>14 427,00<.*C999.*>1 200,00</s', $this->table($html));
        $this->assertStringContainsString('>15 627,00<', $html);

        // les fournisseurs : Fournil, 15 025 achetes, 5 000 payes, 10 025 dus
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/suppliers')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/Sarl Fournil.*>15 025<.*>5 000</s', $table);
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/payable')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/Sarl Fournil.*>15 025,00<.*>5 000,00<.*>10 025,00</s', $table);
        // les articles achetes
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/bought')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/TUBE CUIVRE 14.*>50<.*>12 025<.*VANNE NEUVE.*>3 000</s', $table);

        // jour par jour : aujourd'hui (2 bons, 1 750, 550 de retours), hier (9 500), avant-hier un achat ; une barre par jour
        $html = $this->actingAs($boss)->get('/dashboard/reports/days')->assertOk()->getContent();
        $today = Carbon::parse('2026-11-04')->locale('ar')->translatedFormat('l d/m/Y');
        $this->assertMatchesRegularExpression('/'.preg_quote($today, '/').'.*>1 750<.*>550<.*03\/11\/2026.*>9 500<.*02\/11\/2026.*>15 025</s', $this->table($html));
        $this->assertStringContainsString(route('dashboard.index', ['day' => '2026-11-03']), $html);
        $this->assertStringContainsString('class="bars"', $html);
        // par mois sur toute l'annee : juillet, septembre et novembre
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/days?period=all')->assertOk()->getContent());
        $this->assertSame(3, substr_count($table, 'class="rank">') - 2);

        // qui a saisi les bons
        $table = $this->table($this->actingAs($boss)->get('/dashboard/reports/sellers')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/KARIM.*>2<.*>9 500<.*ADMIN.*>1 750</s', $table);
    }

    public function test_without_price_permissions_no_amount_shows_sorts_or_exports(): void
    {
        [, $oran] = $this->oran();
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $amounts = ['10 200', '11 250', '1 700', '2 100', '1 600', '10200', '11250'];

        // les articles : quantites et bons, classes par quantite ; un tri par montant est ignore
        foreach (['/dashboard/reports/products', '/dashboard/reports/products?sort=amount&dir=asc'] as $url) {
            $html = $this->actingAs($karim)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/BOULON 8\*20.*>34<.*TUBE CUIVRE 14/s', $this->table($html));
            foreach ([...$amounts, __('reports.cols.amount'), __('reports.cols.margin')] as $secret) {
                $this->assertStringNotContainsString(e($secret), $this->table($html), $secret);
            }
            $this->assertStringNotContainsString(e(__('reports.tiles.margin')), $html);
        }
        $csv = $this->actingAs($karim)->get('/dashboard/reports/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('820Z', $csv);
        foreach ($amounts as $secret) {
            $this->assertStringNotContainsString($secret, $csv, $secret);
        }

        // le stock dormant et sa valeur : les quantites, jamais la valeur au prix d'achat ni le prix de detail
        foreach (['dormant' => ['3 600', '3 000'], 'stock' => ['5 600', '8 450', '2 000'], 'customers' => ['10 050', '4 000'], 'days' => ['1 750', '9 500', '15 025'],
            'suppliers' => ['15 025', '5 000'], 'bought' => ['12 025'], 'sellers' => ['9 500']] as $report => $secrets) {
            $html = $this->actingAs($karim)->get('/dashboard/reports/'.$report)->assertOk()->getContent();
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $html, $report.' '.$secret);
            }
        }
        // les dettes ne sont que des montants : fermees, a l'ecran, sur papier et en export
        foreach (['receivable', 'payable'] as $report) {
            $this->actingAs($karim)->get('/dashboard/reports/'.$report)->assertForbidden();
            $this->actingAs($karim)->get('/dashboard/reports/'.$report.'/print')->assertForbidden();
            $this->actingAs($karim)->get('/dashboard/reports/'.$report.'/export')->assertForbidden();
        }
        $html = $this->actingAs($karim)->get('/dashboard/reports/products')->getContent();
        $this->assertStringNotContainsString(route('dashboard.reports', ['report' => 'receivable']), $html);

        // prix de vente sans prix d'achat : les ventes et les dettes des clients, ni marge ni valeur du stock
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->actingAs($seller)->get('/dashboard/reports/products')->assertOk()->getContent();
        $this->assertStringContainsString('>10 200<', $html);
        $this->assertStringNotContainsString('>1 700<', $html);
        $this->actingAs($seller)->get('/dashboard/reports/receivable')->assertOk()->assertSee('14 427,00');
        $this->actingAs($seller)->get('/dashboard/reports/payable')->assertForbidden();
        $this->actingAs($seller)->get('/dashboard/reports/stock')->assertOk()->assertSee('8 450')->assertDontSee('5 600');

        // sans "المبيعات" : rien, et un rapport inconnu n'existe pas
        $nobody = User::factory()->inBranch($oran)->withPermissions([])->create();
        $this->actingAs($nobody)->get('/dashboard/reports/products')->assertForbidden();
        $this->actingAs($nobody)->get('/dashboard/reports/products/export')->assertForbidden();
        $this->actingAs($karim)->get('/dashboard/reports/nope')->assertNotFound();
    }

    public function test_a_user_of_a_never_sees_b_and_the_owner_compares_the_branches(): void
    {
        [, $oran] = $this->oran();
        [$setifToken, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida', '1.0.6');
        $this->stock($setif, 'SET9', 'PIECE DE SETIF', 4, 100, 3333);
        $this->send($setifToken, 'customers', ['p01' => [['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Setif']]]);
        $this->send($setifToken, 'sales', ['2026-11-04' => [$this->bon(70777, '11:00:00', 'C001', [['code' => 'SET9', 'qty' => 3, 'price' => 3333, 'total' => 9999, 'position' => 1]], 'SALIM')]]);

        // Karim voit le stock de Setif (ouvert par defaut), pas ses ventes : aucun rapport ne le montre
        $karim = $this->boss($oran);
        foreach (['products', 'days', 'customers', 'sellers', 'branches', 'dormant', 'reorder', 'stock', 'bought', 'suppliers', 'receivable', 'payable'] as $report) {
            foreach (['', '/print', '/export'] as $form) {
                $response = $this->actingAs($karim)->get('/dashboard/reports/'.$report.$form.'?period=all')->assertOk();
                $content = $form === '/export' ? $response->streamedContent() : $response->getContent();
                foreach (['SET9', 'PIECE DE SETIF', 'Ets Setif', '9 999', '9999', 'SALIM', '21 249'] as $secret) {
                    $this->assertStringNotContainsString($secret, $content, $report.$form.' '.$secret);
                }
            }
        }
        // Setif choisi en haut : rien de Setif, rien d'Oran
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => $setif->id]);
        $this->actingAs($karim)->get('/dashboard/reports/products')->assertOk()->assertDontSee('BOULON')->assertDontSee('SET9')
            ->assertSee(__('documents.no_branch_selected', ['branch' => 'Setif']));
        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => '']);

        // le directeur : les deux branches dans chaque rapport, et leur comparaison ; Blida (agent 1.0.6) est nommee
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);
        $html = $this->actingAs($owner)->get('/dashboard/reports/branches')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Oran.*>11 250<.*52,9%.*>15 025<.*>5 600<.*Setif.*>9 999<.*47,1%.*>400</s', $this->table($html));
        $this->assertStringContainsString(e(__('documents.outdated', ['names' => 'Blida'])), $html);
        $this->assertStringContainsString('>21 249<', $html);
        $table = $this->table($this->actingAs($owner)->get('/dashboard/reports/customers')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/Ets Benali.*Ets Setif/s', $table);
        $this->assertStringContainsString('--bh: '.$setif->hue(), $table);
        $this->actingAs($owner)->get('/dashboard/reports/stock')->assertOk()->assertSee('PIECE DE SETIF')->assertSee('VANNE NEUVE');
    }

    public function test_a_report_prints_and_exports_whole_in_the_order_of_the_screen(): void
    {
        [$token, $oran] = $this->oran();
        // un nom qui commence comme une formule : neutralise dans le fichier pour Excel
        $this->send($token, 'customers', ['p02' => [['key' => 'C777', 'digest' => 9, 'code' => 'C777', 'name' => '=SUM(A1)']]]);
        $this->send($token, 'sales', ['2026-11-01' => [$this->bon(190, '09:00:00', 'C777', [['code' => 'BTH', 'qty' => 1, 'price' => 1, 'total' => 1, 'cost' => 1, 'position' => 1]])]]);
        $boss = $this->boss($oran);

        // sur papier : en francais par defaut, les montants au centime, la periode, le tri, le total
        $html = $this->actingAs($boss)->get('/dashboard/reports/products/print?sort=net&dir=asc')->assertOk()->getContent();
        foreach (['Ventes par article', 'Du 06/10/2026 au 04/11/2026', 'Tri : Net', 'BOULON 8*20', '10 200,00', '11 251,00', 'Ventes nettes'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertMatchesRegularExpression('/GONE1.*TUBE CUIVRE 14.*BOULON 8\*20/s', $html);
        $this->assertStringContainsString('size: A4 landscape', $html);
        // un rapport sans periode : son etat a la date d'aujourd'hui, et le filtre choisi
        $html = $this->actingAs($boss)->get('/dashboard/reports/dormant/print?f=never&lang=ar')->assertOk()->getContent();
        foreach ([__('reports.titles.dormant'), 'VANNE NEUVE', '04/11/2026', __('reports.filters.never')] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('ROBINET', $html);

        // le fichier pour Excel : BOM, point-virgule, virgule decimale, toutes les lignes puis le total
        $response = $this->actingAs($boss)->withCookie('print', json_encode(['lang' => 'fr']))->get('/dashboard/reports/products/export')->assertOk();
        $this->assertStringContainsString('rapport-products-2026-11-04.csv', (string) $response->headers->get('content-disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'Code;Désignation;Classe;Vendu;Retours;Net;Bons;Montant;Part;Bénéfice;Marge;"Stock actuel";"Dernière vente"', $csv);
        $this->assertStringContainsString('820Z;"BOULON 8*20";A;34,00;0,00;34,00;2;10200,00;86,43;1700,00;16,70;0,00;04/11/2026', $csv);
        $this->assertStringContainsString('Total;;;38,00;1,00;37,00;;11251,00;', $csv);
        $csv = $this->actingAs($boss)->get('/dashboard/reports/customers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("C777;'=SUM(A1)", $csv);
        $this->assertStringNotContainsString(';=SUM', $csv);
    }

    public function test_a_long_report_is_paged(): void
    {
        [, $oran] = $this->register('Oran');
        foreach (range(1, 60) as $i) {
            $this->stock($oran, sprintf('P%03d', $i), 'PIECE '.$i, 100 - $i, 10);
        }
        $boss = $this->boss($oran);

        $html = $this->actingAs($boss)->get('/dashboard/reports/stock')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('reports.page', ['page' => 1, 'pages' => 2])), $html);
        $this->assertStringContainsString('P001', $html);
        $this->assertStringNotContainsString('P060', $html);
        $next = route('dashboard.reports', ['report' => 'stock', 'page' => 2]);
        $this->assertStringContainsString(e($next), $html);
        // la page 2 : les 10 derniers, numerotes a la suite ; une page au-dela = la derniere
        foreach ([$next, '/dashboard/reports/stock?page=9'] as $url) {
            $table = $this->table($this->actingAs($boss)->get($url)->assertOk()->getContent());
            $this->assertStringContainsString('P060', $table);
            $this->assertStringContainsString('class="rank">60<', $table);
            $this->assertStringNotContainsString('P001', $table);
        }
        // le total reste celui de toute la liste : 60 articles, de 99 a 40 en stock, a 10 l'unite
        $this->assertStringContainsString('>41 700<', $this->table($html));
    }

    public function test_the_dashboard_links_to_the_whole_lists(): void
    {
        [, $oran] = $this->oran();
        $boss = $this->boss($oran);

        $html = $this->actingAs($boss)->get('/dashboard?day=2026-11-03&rank=qty')->assertOk()->getContent();
        foreach ([route('dashboard.reports', ['report' => 'products', 'sort' => 'net', 'day' => '2026-11-03']), route('dashboard.reports', ['report' => 'customers', 'day' => '2026-11-03']),
            route('dashboard.reports', ['report' => 'reorder', 'day' => '2026-11-03']), route('dashboard.reports', ['report' => 'dormant']),
            route('dashboard.reports', ['report' => 'products']), route('dashboard.reports', ['report' => 'receivable'])] as $url) {
            $this->assertStringContainsString(e($url), $html, $url);
        }
    }
}
