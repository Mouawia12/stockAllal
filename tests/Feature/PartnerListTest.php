<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clients et fournisseurs (PartnerController) : la liste (periode, actifs, bloques, recherche, les plus gros d'abord)
 * et la fiche (coordonnees, bons, versements, articles). sales.view ; montants selon les permissions de prix.
 */
class PartnerListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function register(string $name): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => '1.0.7',
        ])->assertCreated();
        $branch = Branch::findOrFail($res->json('branch_id'));
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => '820Z', 'code_key' => '820Z', 'designation' => 'BOULON 8*20',
            'stock' => 10, 'seen_at' => now(), 'updated_at' => now()]);

        return [$res->json('token'), $branch];
    }

    /**
     * Ets Benali : deux bons aujourd'hui (1200, puis un echange de 550) et deux versements (1000 en especes, -200 "Autre") ;
     * El Amel (bloque) : un bon de retour il y a 40 jours ; Sarl Atlas : un bon il y a plus d'un an. Un fournisseur, un achat.
     */
    private function history(string $token): void
    {
        $post = fn (string $set, array $groups) => $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $set, 'groups' => $groups])->assertOk();
        $post('customers', ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali', 'phone' => '0550 12 34 56', 'wilaya' => 'Oran', 'nif' => '000216001234567',
                'credit_limit' => 50000, 'opening_balance' => 1200, 'blocked' => 'Non'],
            ['key' => 'C002', 'digest' => 2, 'code' => 'C002', 'name' => 'Quincaillerie El Amel', 'blocked' => 'Oui'],
            ['key' => 'C003', 'digest' => 3, 'code' => 'C003', 'name' => 'Sarl Atlas'],
        ]]);
        $post('suppliers', ['p02' => [['key' => 'F01', 'digest' => 4, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $post('sales', [
            now()->subDays(400)->toDateString() => [['key' => '100', 'digest' => 5, 'number' => 100, 'partner' => 'C003', 'amount' => 500, 'lines' => [
                ['code' => '820Z', 'qty' => 1, 'total' => 500],
            ]]],
            now()->subDays(40)->toDateString() => [['key' => '182', 'digest' => 6, 'number' => 182, 'partner' => 'C002', 'amount' => -4400, 'lines' => [
                ['code' => '820Z', 'qty' => -25, 'price' => 300, 'total' => -7500],
                ['code' => '820Z', 'qty' => 10, 'price' => 310, 'total' => 3100],
            ]]],
            now()->toDateString() => [
                ['key' => '183', 'digest' => 7, 'number' => 183, 'partner' => 'C001', 'amount' => 1200, 'lines' => [
                    ['code' => '820z', 'qty' => 4, 'price' => 300, 'total' => 1200],
                ]],
                ['key' => '184', 'digest' => 8, 'number' => 184, 'partner' => 'C001', 'amount' => 550, 'lines' => [
                    ['code' => '820Z', 'qty' => 2, 'price' => 550, 'total' => 1100],
                    ['code' => 'GONE1', 'qty' => -1, 'price' => 550, 'total' => -550],
                ]],
            ],
        ]);
        $post('customer_payments', [
            now()->subDay()->toDateString() => [['key' => 'a|1', 'digest' => 9, 'number' => 1, 'partner' => 'C001', 'type' => 'Autre', 'amount' => -200]],
            now()->toDateString() => [['key' => 'b|2', 'digest' => 10, 'number' => 2, 'partner' => 'C001', 'type' => 'Espéce', 'amount' => 1000,
                'document_number' => 183, 'time' => '11:20:00.0000']],
        ]);
        $post('purchases', [now()->subDays(2)->toDateString() => [['key' => '40|F01', 'digest' => 11, 'number' => 40, 'partner' => 'F01', 'amount' => 12025,
            'lines' => [['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025]]]]]);
        $post('supplier_payments', [now()->subDay()->toDateString() => [['key' => 'c|3', 'digest' => 12, 'number' => 3, 'partner' => 'F01', 'type' => 'Espéce', 'amount' => 5000]]]);
    }

    private function partner(string $code): Partner
    {
        return Partner::where('code', $code)->firstOrFail();
    }

    public function test_the_customers_of_its_branch_biggest_first_with_period_filters_and_search(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // un an par defaut : Benali (1750) avant El Amel (le retour, -4400) ; Atlas sans bon dans l'annee, mais dans la liste
        $html = $this->actingAs($karim)->get('/customers')->assertOk()->getContent();
        foreach (['Ets Benali', 'C001', '0550 12 34 56', '1 750,00', '-4 400,00', 'Sarl Atlas', __('partners.blocked')] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertLessThan(strpos($html, 'Quincaillerie El Amel'), strpos($html, 'Ets Benali'));
        $this->assertStringContainsString(route('customers.show', $this->partner('C001')), $html);

        // actifs sur la periode, bloques, 30 jours
        $html = $this->actingAs($karim)->get('/customers?f=active')->getContent();
        $this->assertStringContainsString('Quincaillerie El Amel', $html);
        $this->assertStringNotContainsString('Sarl Atlas', $html);
        $html = $this->actingAs($karim)->get('/customers?f=blocked')->getContent();
        $this->assertStringContainsString('Quincaillerie El Amel', $html);
        $this->assertStringNotContainsString('Ets Benali', $html);
        $html = $this->actingAs($karim)->get('/customers?f=active&period=30')->getContent();
        $this->assertStringContainsString('Ets Benali', $html);
        $this->assertStringNotContainsString('Quincaillerie El Amel', $html);

        // recherche : le nom, le code, le telephone (le mot cherche est dans un <mark> : le texte sans balises)
        foreach (['amel' => 'Quincaillerie El Amel', 'c003' => 'Sarl Atlas', '0550' => 'Ets Benali'] as $q => $who) {
            $html = $this->actingAs($karim)->get('/customers?q='.$q)->getContent();
            $this->assertStringContainsString($who, strip_tags($html), $q);
            $this->assertSame(1, substr_count($html, 'class="who"'), $q);
        }

        // les fournisseurs : l'achat sans son montant (pas prices.buy)
        $html = $this->actingAs($karim)->get('/suppliers')->assertOk()->getContent();
        $this->assertStringContainsString('Sarl Fournil', $html);
        $this->assertStringNotContainsString('12 025,00', $html);

        // le nom du client sur la liste des bons et sur la page d'un bon mene a sa fiche
        $card = route('customers.show', $this->partner('C001'));
        $this->assertStringContainsString($card, $this->actingAs($karim)->get('/sales')->getContent());
        $this->assertStringContainsString($card, $this->actingAs($karim)->get(route('sales.show', Document::where('number', 183)->first()))->getContent());
        $this->assertStringContainsString($card, $this->actingAs($karim)->get('/stock/item?code=820Z')->getContent());
    }

    public function test_the_card_of_a_customer_and_its_bons_payments_and_items_in_the_window(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $benali = $this->partner('C001');

        $html = $this->actingAs($karim)->get(route('customers.show', $benali))->assertOk()->getContent();
        foreach (['Ets Benali', '0550 12 34 56', '000216001234567', '50 000,00', '1 200,00', '1 750,00', '550,00', '800,00', 'Espéce', 'Autre', '-200,00',
            'BOULON 8*20', 'data-open="bons"', 'data-open="payments"', 'data-open="items"'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // le solde comme Gescomm : tous les bons (1200 + 550) moins tous les versements (1000 et -200 "Autre"), quelle
        // que soit la periode de la fiche ; aussi sur le releve imprime
        $balance = '<span class="wb-value">950,00</span>';
        $this->assertStringContainsString($balance, $html);
        $this->assertStringContainsString(__('partners.balance_side.customer.pos'), $html);
        $this->assertStringContainsString($balance, $this->actingAs($karim)->get(route('customers.show', $benali).'?period=7')->getContent());
        $this->actingAs($karim)->get(route('customers.print-card', ['partner' => $benali, 'period' => '7']))->assertOk()
            ->assertSee(__('partners.balance_now'))->assertSee('950,00');
        // le detail n'est plus dans la page : la fenetre le demande
        $this->assertStringNotContainsString('<tr data-row', $html);

        $records = fn (Partner $p, array $query) => $this->actingAs($karim)->get(route('customers.records', $p).'?'.http_build_query($query))->assertOk()->getContent();
        $bon183 = route('sales.show', Document::where('number', 183)->first());
        $this->assertStringContainsString('href="'.$bon183.'"', $records($benali, ['tab' => 'bons']));
        // le bon du versement de 1000
        $html = $records($benali, ['tab' => 'payments']);
        $this->assertStringContainsString('href="'.$bon183.'"', $html);
        foreach (['Espéce', 'Autre', '-200,00', '1 000,00'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $html = $records($benali, ['tab' => 'items']);
        foreach (['BOULON 8*20', 'GONE1', '2 300,00', e(route('stock.item', ['code' => '820Z']))] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        // 30 jours : le retour d'El Amel (40 jours) n'y est plus
        $this->assertStringContainsString(__('partners.none_bons'), $records($this->partner('C002'), ['tab' => 'bons', 'period' => '30']));
        $html = $this->actingAs($karim)->get(route('customers.show', $this->partner('C002')))->getContent();
        $this->assertStringContainsString('7 500,00', $html, 'le retour, en montant');

        // une fiche de client ne s'ouvre pas comme un fournisseur
        $this->actingAs($karim)->get(route('suppliers.show', $benali))->assertNotFound();
        $this->actingAs($karim)->get(route('suppliers.records', $benali))->assertNotFound();
    }

    public function test_without_sales_view_or_prices_the_cards_show_nothing_they_should_not(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $benali = $this->partner('C001');

        $plain = User::factory()->inBranch($oran)->create();
        $this->actingAs($plain)->get('/customers')->assertForbidden();
        $this->actingAs($plain)->get('/suppliers')->assertForbidden();
        $this->actingAs($plain)->get(route('customers.show', $benali))->assertForbidden();

        // sales.view seul : les gens et leurs bons, aucun montant
        $noPrices = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->actingAs($noPrices)->get('/customers')->assertOk()->getContent();
        $this->assertStringContainsString('Ets Benali', $html);
        $this->assertStringNotContainsString('1 750,00', $html);
        $html = $this->actingAs($noPrices)->get(route('customers.show', $benali))->assertOk()->getContent();
        foreach (['1 750,00', '50 000,00', '1 200,00', '800,00', '-200,00', '2 300,00', '950,00'] as $money) {
            $this->assertStringNotContainsString($money, $html, $money);
        }
        $this->assertStringNotContainsString('class="who-balance"', $html, 'no balance without prices');
        $this->assertStringContainsString('BOULON 8*20', $html);

        // un fournisseur : ses montants demandent prices.buy
        $fournil = $this->partner('F01');
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->assertStringNotContainsString('12 025,00', $this->actingAs($seller)->get(route('suppliers.show', $fournil))->getContent());
        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.buy'])->create();
        // le solde d'un fournisseur : ce qu'on lui doit (12025 - 5000)
        $this->actingAs($buyer)->get(route('suppliers.show', $fournil))->assertOk()->assertSee('12 025,00')->assertSee('5 000,00')
            ->assertSee('<span class="wb-value">7 025,00</span>', false)->assertSee(__('partners.balance_side.supplier.pos'));
        $this->assertStringNotContainsString('7 025,00', $this->actingAs($seller)->get(route('suppliers.show', $fournil))->getContent());
    }
}
