<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les pages d'impression (resources/views/print) : les memes branches et les memes permissions que les pages d'ou elles
 * viennent. Une branche que le compte ne voit pas n'existe pas (404), un prix qu'il ne peut pas voir n'est pas dans le HTML.
 */
class PrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 11:00:00');
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
        $branch = Branch::findOrFail($res->json('branch_id'));
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => '820Z', 'code_key' => '820Z', 'designation' => 'BOULON 8*20 '.$name, 'unit' => 'PCE',
            'stock' => 12, 'price' => 300, 'price_wholesale' => 280, 'price_buy' => 111.5, 'seen_at' => now(), 'updated_at' => now()]);

        return [$res->json('token'), $branch];
    }

    /** Un client, deux bons (dont un avec un retour et une remise), un versement ; un fournisseur et son bon ; la caisse. */
    private function history(string $token, string $customer = 'Ets Benali', int $first = 183): void
    {
        $post = fn (string $set, array $groups) => $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $set, 'groups' => $groups])->assertOk();
        $post('customers', ['p01' => [['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => $customer, 'phone' => '0550 12 34 56',
            'address' => '12 rue de Oran', 'wilaya' => 'Oran', 'rc' => 'RC-77', 'nif' => 'NIF-88']]]);
        $post('suppliers', ['p01' => [['key' => 'F01', 'digest' => 2, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $post('sales', [
            '2026-10-01' => [['key' => (string) $first, 'digest' => 3, 'number' => $first, 'partner' => 'C001', 'time' => '09:18:33.0000', 'amount' => 1200, 'served_by' => 'Bilal',
                'lines' => [['code' => '820Z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 250, 'position' => 1]]]],
            '2026-11-05' => [['key' => (string) ($first + 1), 'digest' => 4, 'number' => $first + 1, 'partner' => 'C001', 'time' => '10:05:00', 'amount' => 126200, 'discount' => 50, 'gross' => 126250,
                'payment' => 'Espéce', 'credit_days' => 15, 'note' => 'echange', 'lines' => [
                    ['code' => '820Z', 'qty' => 2, 'price' => 300, 'discount' => 10, 'total' => 126750, 'cost' => 250, 'position' => 1],
                    ['code' => 'GONE1', 'qty' => -1, 'price' => 550, 'total' => -550, 'position' => 2],
                ]]],
        ]);
        $post('purchases', ['2026-11-04' => [['key' => '40|F01', 'digest' => 5, 'number' => 40, 'partner' => 'F01', 'amount' => 12025, 'lines' => [
            ['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025, 'position' => 1],
        ]]]]);
        $post('customer_payments', [
            '2026-10-15' => [['key' => 'v|1', 'digest' => 6, 'number' => 1, 'partner' => 'C001', 'time' => '08:00:00', 'type' => 'Espéce', 'amount' => 700, 'document_number' => $first]],
            '2026-11-05' => [['key' => 'v|2', 'digest' => 7, 'number' => 2, 'partner' => 'C001', 'time' => '12:00:00', 'type' => 'Autre', 'amount' => -667.5, 'cheque' => 'CH-55', 'note' => 'Solde Initial']],
        ]);
        $post('supplier_payments', ['2026-11-04' => [['key' => 'w|1', 'digest' => 8, 'number' => 1, 'partner' => 'F01', 'type' => 'Espéce', 'amount' => 5000]]]);
        $post('cash_in', [
            '2026-11-04' => [['key' => '2026-11-04|1', 'digest' => 9, 'number' => '1', 'time' => '09:00:00.0000', 'amount' => '1000.00', 'label' => 'Client: OTHMANI', 'entered_by' => 'ADMIN']],
            '2026-11-05' => [['key' => '2026-11-05|2', 'digest' => 10, 'number' => '2', 'time' => '10:00:00.0000', 'amount' => '500.00', 'label' => 'Client: BRAHIMI', 'entered_by' => 'ADMIN']],
        ]);
        $post('cash_out', ['2026-11-05' => [['key' => '2026-11-05|7', 'digest' => 11, 'number' => '7', 'time' => '11:00:00.0000', 'amount' => '200.00', 'label' => 'Fourn: TPL', 'note' => 'MAA OUSSAMA', 'entered_by' => 'ADMIN']]]);
    }

    private function bon(int $number, ?Branch $branch = null): Document
    {
        return Document::where('number', $number)->when($branch, fn ($q) => $q->where('branch_id', $branch->id))->firstOrFail();
    }

    public function test_a_bon_prints_with_its_customer_lines_totals_and_amount_in_words(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        $html = $this->actingAs($karim)->get('/sales/'.$this->bon(184)->id.'/print')->assertOk()->getContent();
        // en francais par defaut, quelle que soit la langue de l'interface (arabe ici)
        $this->assertStringContainsString('<html lang="fr" dir="ltr">', $html);
        foreach (['Bon de vente', '05/11/2026 — 10:05', 'Ets Benali', 'C001', '12 rue de Oran', '0550 12 34 56', 'RC : RC-77', 'NIF : NIF-88',
            'Espéce', '15 jours', 'BOULON 8*20 Oran', 'PCE', 'Retour', '126 200,00', '126 250,00', 'Total remise',
            'cent vingt-six mille deux cents dinars', 'echange', 'Signature du client'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // jamais sur le papier : la marge, le prix d'achat du jour, le stock actuel
        $this->assertStringNotContainsString('Marge', $html);
        $this->assertStringNotContainsString('250,00', str_replace('126 250,00', '', $html));
        // la remise est une colonne seulement si le bon en a une
        $this->assertStringContainsString('Remise %', $html);
        $this->assertStringNotContainsString('Remise %', $this->actingAs($karim)->get('/sales/'.$this->bon(183)->id.'/print')->getContent());
    }

    public function test_without_the_price_permission_a_bon_prints_without_any_price_and_cannot_ask_for_them(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $worker = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();

        foreach (['', '?prices=1'] as $query) {
            $html = $this->actingAs($worker)->get('/sales/'.$this->bon(183)->id.'/print'.$query)->assertOk()->getContent();
            $this->assertStringContainsString('BOULON 8*20 Oran', $html);
            $this->assertStringNotContainsString('1 200,00', $html);
            $this->assertStringNotContainsString('300,00', $html);
            $this->assertStringNotContainsString('dinars', $html);
            // une case devant chaque ligne, pour preparer la marchandise
            $this->assertStringContainsString('class="box"', $html);
            $this->assertStringContainsString('Préparé par', $html);
        }

        // celui qui voit les prix peut les retirer du papier
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->actingAs($karim)->get('/sales/'.$this->bon(183)->id.'/print?prices=0')->assertOk()->getContent();
        $this->assertStringNotContainsString('1 200,00', $html);
        // les achats demandent prices.buy, pas prices.sale
        $this->assertStringNotContainsString('12 025,00', $this->actingAs($karim)->get('/purchases/'.$this->bon(40)->id.'/print')->assertOk()->getContent());
    }

    public function test_a_branch_the_account_does_not_see_does_not_exist_on_paper(): void
    {
        [$tokenA, $oran] = $this->register('Oran');
        [$tokenB, $setif] = $this->register('Setif');
        $this->history($tokenA, 'Client Oran', 183);
        $this->history($tokenB, 'Client Setif', 283);
        DB::table('branch_access')->where('module', 'stock')->delete();
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy', 'cash.view'])->create();
        $this->actingAs($karim);

        $theirCustomer = Partner::where('branch_id', $setif->id)->where('kind', 'customer')->firstOrFail();
        $theirSupplier = Partner::where('branch_id', $setif->id)->where('kind', 'supplier')->firstOrFail();
        foreach (['/sales/'.$this->bon(283, $setif)->id.'/print', '/purchases/'.$this->bon(40, $setif)->id.'/print',
            "/customers/{$theirCustomer->id}/print", "/suppliers/{$theirSupplier->id}/print"] as $url) {
            $this->get($url)->assertNotFound();
        }

        foreach (['/sales/print?period=all', '/purchases/print?period=all', '/customers/print?period=all', '/suppliers/print?period=all',
            '/customers/payments/print?period=all', '/suppliers/payments/print?period=all', '/cash/print?period=all', '/stock/print', '/stock/item/print?code=820Z'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Setif', $html, $url);
            $this->assertStringNotContainsString('Client Setif', $html, $url);
        }
        $this->assertStringContainsString('183', $this->get('/sales/print?period=all')->getContent());
        $this->assertStringNotContainsString('283', $this->get('/sales/print?period=all')->getContent());

        // un article connu seulement dans l'autre branche
        BranchStock::create(['branch_id' => $setif->id, 'code_raw' => 'SEUL', 'code_key' => 'SEUL', 'designation' => 'SEULEMENT SETIF', 'stock' => 1, 'seen_at' => now(), 'updated_at' => now()]);
        $this->get('/stock/item/print?code=SEUL')->assertNotFound();
        $this->assertStringNotContainsString('SEULEMENT', $this->get('/stock/print')->getContent());
    }

    public function test_the_routes_ask_for_the_same_permissions_as_their_pages(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $document = $this->bon(183);
        $partner = Partner::where('kind', 'customer')->firstOrFail();

        $this->get('/sales/print')->assertRedirect('/login');
        $this->get('/stock/print')->assertRedirect('/login');

        $worker = User::factory()->inBranch($oran)->withPermissions([])->create();
        $this->actingAs($worker);
        foreach (['/sales/print', "/sales/{$document->id}/print", '/customers/print', "/customers/{$partner->id}/print", '/customers/payments/print', '/cash/print'] as $url) {
            $this->get($url)->assertForbidden();
        }
        // le stock est ouvert a tous, sans prix
        $this->get('/stock/print')->assertOk()->assertSee('BOULON 8*20 Oran')->assertDontSee('300,00');

        // la caisse demande cash.view, meme avec les ventes
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($seller)->get('/cash/print')->assertForbidden();
    }

    public function test_the_journal_prints_all_the_bons_of_a_period_day_by_day(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim);

        // la periode de la liste : 30 jours, donc seulement le bon du 05/11
        $html = $this->get('/sales/print')->assertOk()->getContent();
        $this->assertStringContainsString('Journal des ventes', $html);
        $this->assertStringContainsString('Du 08/10/2026 au 06/11/2026', $html);
        $this->assertStringContainsString('126 200,00', $html);
        $this->assertStringNotContainsString('1 200,00', str_replace('126 200,00', '', $html));

        // des dates choisies, du plus ancien au plus recent, le total de la periode
        $html = $this->get('/sales/print?from=2026-09-01&to=2026-11-30')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '05/11/2026'), strpos($html, '01/10/2026'));
        $this->assertStringContainsString('127 400,00', $html);
        $this->assertStringContainsString('Total (2 bons)', $html);
        $this->assertSame(1, substr_count($this->get('/sales/print?from=2026-10-01&to=2026-10-01')->getContent(), 'class="group"'));
        $this->assertStringContainsString('Journée du 01/10/2026', $this->get('/sales/print?from=2026-10-01&to=2026-10-01')->getContent());
        // des dates a l'envers se remettent dans l'ordre ; une date invalide est ignoree
        $this->assertStringContainsString('Du 01/10/2026 au 01/11/2026', $this->get('/sales/print?from=2026-11-01&to=2026-10-01')->getContent());
        $this->get('/sales/print?from=2026-02-31&to=nope')->assertOk()->assertSee('Du 08/10/2026 au 06/11/2026');

        // la recherche et le filtre de la liste
        $this->assertStringContainsString('Recherche : Benali', $this->get('/sales/print?period=all&q=Benali')->getContent());
        $this->assertStringContainsString('Rien à imprimer', $this->get('/sales/print?period=all&q=personne')->getContent());
        $returns = $this->get('/sales/print?period=all&f=returns')->getContent();
        $this->assertStringContainsString('Total (1 bon)', $returns);

        // sans le prix : les bons sans leur montant
        $worker = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->actingAs($worker)->get('/sales/print?period=all')->assertOk()->getContent();
        $this->assertStringContainsString('183', $html);
        $this->assertStringNotContainsString('126 200,00', $html);
    }

    public function test_the_customer_and_supplier_lists_print_whole_with_their_bons_and_payments(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        Partner::query()->whereKey(Partner::where('kind', 'customer')->value('id'))->update(['blocked' => true]);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim);

        $html = $this->get('/customers/print?period=all')->assertOk()->getContent();
        foreach (['Liste des clients', 'Ets Benali', 'C001', '0550 12 34 56', '12 rue de Oran', '127 400,00', '32,50', 'Bloqué'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('Rien à imprimer', $this->get('/customers/print?q=personne')->getContent());
        $this->assertStringContainsString('Avec un bon sur la période', $this->get('/customers/print?period=all&f=active')->getContent());

        // les achats demandent prices.buy
        $html = $this->get('/suppliers/print?period=all')->assertOk()->getContent();
        $this->assertStringContainsString('Liste des fournisseurs', $html);
        $this->assertStringContainsString('Sarl Fournil', $html);
        $this->assertStringNotContainsString('12 025,00', $html);
        $this->assertStringNotContainsString('5 000,00', $html);
    }

    public function test_the_statement_lists_bons_and_payments_by_date_without_a_balance(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $partner = Partner::where('kind', 'customer')->firstOrFail();
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim);

        $html = $this->get("/customers/{$partner->id}/print")->assertOk()->getContent();
        foreach (['Relevé du client', 'Ets Benali', 'Tout l’historique', '127 400,00', '32,50', 'Espéce', 'Autre', 'Chèque CH-55', 'Solde Initial'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // le bon du 01/10, le versement du 15/10, le bon du 05/11, le versement du 05/11 a 12 h
        $positions = array_map(fn (string $d) => strpos($html, $d), ['01/10/2026', '15/10/2026', '05/11/2026']);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $table = substr($html, strpos($html, '<table'));
        $this->assertLessThan(strpos($table, '-667,50'), strpos($table, '126 200,00'));
        // pas de solde tant que le calcul de Gescomm n'est pas verifie : le mot n'est que dans la barre, jamais sur la feuille
        $this->assertDoesNotMatchRegularExpression('/Solde (client|fournisseur)/i', substr($html, strpos($html, '<main')));

        // les versements seulement, sur une periode
        $html = $this->get("/customers/{$partner->id}/print?show=payments&from=2026-10-01&to=2026-10-31")->assertOk()->getContent();
        $this->assertStringContainsString('Versements du client', $html);
        $this->assertStringContainsString('700,00', $html);
        $this->assertStringNotContainsString('-667,50', $html);
        $this->assertStringNotContainsString('1 200,00', $html);

        // les bons seulement, les articles
        $this->assertStringNotContainsString('700,00', $this->get("/customers/{$partner->id}/print?show=bons")->getContent());
        $html = $this->get("/customers/{$partner->id}/print?show=items")->assertOk()->getContent();
        $this->assertStringContainsString('BOULON 8*20 Oran', $html);
        $this->assertStringContainsString('Articles achetés par le client', $html);

        // sans le prix : les mouvements sans montant
        $worker = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->actingAs($worker)->get("/customers/{$partner->id}/print")->assertOk()->getContent();
        $this->assertStringContainsString('Espéce', $html);
        $this->assertStringNotContainsString('700,00', $html);
        $this->assertStringNotContainsString('1 200,00', $html);
    }

    public function test_the_payments_of_all_customers_day_by_day_with_the_total_by_type(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim);

        $html = $this->get('/customers/payments/print?from=2026-10-01&to=2026-11-06')->assertOk()->getContent();
        foreach (['Versements des clients', 'Ets Benali', 'C001', 'CH-55', '183', 'Solde Initial', '700,00', '-667,50', '32,50', 'Total (2)'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('5 000,00', $html);
        $this->assertStringNotContainsString('Sarl Fournil', $html);
        // les versements aux fournisseurs : prices.buy
        $this->assertStringNotContainsString('5 000,00', $this->get('/suppliers/payments/print?from=2026-11-01')->assertOk()->getContent());
        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.buy'])->create();
        $this->assertStringContainsString('5 000,00', $this->actingAs($buyer)->get('/suppliers/payments/print?from=2026-11-01')->getContent());
    }

    public function test_the_stock_prints_every_item_with_the_prices_the_account_may_see(): void
    {
        [$token, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        // les prix sont ceux de la branche principale, comme dans la recherche
        $oran->forceFill(['is_main' => true])->save();
        BranchStock::create(['branch_id' => $oran->id, 'code_raw' => 'A1', 'code_key' => 'A1', 'designation' => 'ZINC', 'stock' => 0, 'seen_at' => now(), 'updated_at' => now()]);
        BranchStock::create(['branch_id' => $oran->id, 'code_raw' => 'B2', 'code_key' => 'B2', 'designation' => 'ACIER', 'stock' => -3, 'seen_at' => now(), 'updated_at' => now()]);
        $developer = User::factory()->admin()->create();
        $this->actingAs($developer);

        // sans mot : tout le catalogue, le stock de chaque branche, le total, tous les prix pour le developpeur
        $html = $this->get('/stock/print')->assertOk()->getContent();
        foreach (['État du stock', '3 articles', 'BOULON 8*20', 'ZINC', 'ACIER', '-3', '24', 'Setif', 'Oran', '111,50'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // un seul filtre, et le tri par designation
        $html = $this->get('/stock/print?f=negative')->getContent();
        $this->assertStringContainsString('ACIER', $html);
        $this->assertStringNotContainsString('ZINC', $html);
        $html = $this->get('/stock/print?sort=name')->getContent();
        $this->assertLessThan(strpos($html, 'ZINC'), strpos($html, 'ACIER'));
        $html = $this->get('/stock/print?sort=code')->getContent();
        $this->assertLessThan(strpos($html, 'ACIER'), strpos($html, 'ZINC'));
        // l'inventaire : pas de prix, une colonne pour ce qui est compte
        $html = $this->get('/stock/print?mode=count')->getContent();
        $this->assertStringContainsString('Fiche d’inventaire', $html);
        $this->assertStringContainsString('Compté', $html);
        $this->assertStringNotContainsString('111,50', $html);

        // sans prices.buy : pas le prix d'achat ; sans rien : aucun prix
        $seller = User::factory()->inBranch($oran)->withPermissions(['prices.sale'])->create();
        $html = $this->actingAs($seller)->get('/stock/print')->assertOk()->getContent();
        $this->assertStringContainsString('300,00', $html);
        $this->assertStringNotContainsString('111,50', $html);
    }

    public function test_the_stock_card_of_an_item_with_its_sales_and_purchases(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        $html = $this->actingAs($karim)->get('/stock/item/print?code=820z')->assertOk()->getContent();
        foreach (['Fiche de stock', '820Z', 'BOULON 8*20 Oran', 'Oran', '300,00', 'Lignes de vente', 'Lignes d’achat', 'Ets Benali', '183', 'Sarl Fournil'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // pas de montant d'achat ni de marge sans prices.buy
        $this->assertStringNotContainsString('12 025,00', $html);
        $this->assertStringNotContainsString('Marge', $html);
        // sans sales.view : le stock seul
        $worker = User::factory()->inBranch($oran)->withPermissions([])->create();
        $html = $this->actingAs($worker)->get('/stock/item/print?code=820Z')->assertOk()->getContent();
        $this->assertStringContainsString('BOULON 8*20 Oran', $html);
        $this->assertStringNotContainsString('Ets Benali', $html);
        $this->assertStringNotContainsString('300,00', $html);
    }

    public function test_the_cash_movements_print_with_the_balance_at_each_end_of_day(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'cash.view'])->create();
        $this->actingAs($karim);

        // un jour : ce qu'il y avait la veille, ce qui est entre et sorti, ce qui reste
        $html = $this->get('/cash/print?from=2026-11-05&to=2026-11-05')->assertOk()->getContent();
        foreach (['Mouvements de caisse', 'Journée du 05/11/2026', 'Client: BRAHIMI', 'Fourn: TPL', 'MAA OUSSAMA', 'ADMIN', '1 000,00', '500,00', '200,00', '1 300,00'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('Client: OTHMANI', $html);
        // les sorties seulement : le meme solde, seulement leurs lignes
        $html = $this->get('/cash/print?from=2026-11-05&to=2026-11-05&dir=out')->getContent();
        $this->assertStringContainsString('Sorties de caisse', $html);
        $this->assertStringContainsString('1 300,00', $html);
        $this->assertStringNotContainsString('Client: BRAHIMI', $html);
        // un agent d'avant la caisse est nomme, jamais une caisse vide
        [, $old] = $this->register('Vieille', '1.0.7');
        $owner = User::factory()->admin()->create();
        $this->assertStringContainsString('Vieille', $this->actingAs($owner)->get('/cash/print?period=all')->getContent());
    }

    public function test_language_and_size_are_chosen_on_the_page_and_stay_in_this_browser(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim);
        $url = '/sales/'.$this->bon(184)->id.'/print';

        $res = $this->get($url.'?lang=ar&size=a5&code=0')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertStringContainsString('size: A5', $html);
        // le montant en lettres n'existe qu'en francais
        $this->assertStringNotContainsString('dinars', $html);
        $this->assertStringContainsString(__('documents.bon.sale', [], 'ar'), $html);
        $res->assertCookie('print');

        // la prochaine impression les garde
        $this->withCookie('print', json_encode(['lang' => 'ar', 'size' => 'a5', 'code' => '0']));
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertStringContainsString('size: A5', $html);
        $this->assertStringNotContainsString('class="code ltr">820Z', $html);
        $this->withCookie('print', json_encode(self::class));

        // une valeur inconnue est ignoree
        $this->get($url.'?lang=de&size=z9')->assertOk()->assertSee('<html lang="fr" dir="ltr">', false)->assertSee('size: A4', false);
    }

    public function test_the_print_header_of_a_branch_is_entered_on_the_branches_page(): void
    {
        [$token, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        $this->history($token);
        $developer = User::factory()->admin()->create();
        $this->actingAs($developer);

        // sans en-tete : le nom de la branche
        $this->assertStringContainsString('lh-name"><bdi>Oran', $this->get('/sales/'.$this->bon(183)->id.'/print')->getContent());

        $this->get('/branches')->assertOk()->assertSee(route('branches.print-header', $oran), false);
        $this->get("/branches/{$oran->id}/print-header")->assertOk()->assertSee('name="print_header"', false);
        $this->put("/branches/{$oran->id}/print-header", [
            'print_header' => "SARL ALLAL INDUSTRIE\n\n  Quincaillerie générale  \nRC 12345",
            'print_footer' => 'RIB 00799',
        ])->assertRedirect('/branches');
        $this->assertSame(1, DB::table('activity_log')->where('action', 'branch.updated')->count());

        $html = $this->get('/sales/'.$this->bon(183)->id.'/print')->getContent();
        $this->assertStringContainsString('lh-name"><bdi>SARL ALLAL INDUSTRIE', $html);
        $this->assertStringContainsString('lh-line"><bdi>Quincaillerie générale', $html);
        $this->assertStringContainsString('RC 12345', $html);
        $this->assertStringContainsString('RIB 00799', $html);

        // les branches gerees seulement, avec la permission
        $karim = User::factory()->inBranch($oran)->withPermissions(['branches.view'])->create();
        $this->actingAs($karim)->get("/branches/{$oran->id}/print-header")->assertForbidden();
        $manager = User::factory()->inBranch($oran)->withPermissions(['branches.view', 'branches.manage'])->create();
        DB::table('branch_access')->delete();
        $this->actingAs($manager)->get("/branches/{$setif->id}/print-header")->assertNotFound();
        $this->actingAs($manager)->put("/branches/{$setif->id}/print-header", ['print_header' => 'X'])->assertNotFound();
    }

    public function test_the_pages_link_to_their_prints_with_their_filters(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'cash.view'])->create();
        $this->actingAs($karim);
        $partner = Partner::where('kind', 'customer')->firstOrFail();

        $see = fn (string $page, string $link) => $this->get($page)->assertOk()->assertSee(e($link), false);
        $see('/sales?q=Benali&period=7&f=returns', route('sales.print', ['q' => 'Benali', 'period' => '7', 'f' => 'returns']));
        $see('/sales/'.$this->bon(183)->id, route('sales.print-bon', ['document' => $this->bon(183)->id, 'auto' => 1]));
        $see('/customers?q=Benali', route('customers.print', ['q' => 'Benali', 'period' => '365']));
        $see('/customers?q=Benali', route('customers.print-payments'));
        $see("/customers/{$partner->id}", route('customers.print-card', ['partner' => $partner->id, 'show' => 'payments']));
        $see('/stock?q=boulon', route('stock.print', ['q' => 'boulon']));
        $see('/stock?q=boulon&partial=1', route('stock.print', ['q' => 'boulon']));
        $see('/stock/item?code=820Z', route('stock.print-item', ['code' => '820Z']));
        $this->get('/dashboard')->assertOk()->assertSee('window.print()', false)->assertSee('/cash/print?from=', false);
        // la fusion des codes n'imprime pas
        $merger = User::factory()->admin()->create();
        $this->actingAs($merger)->get('/merge?q=boulon')->assertOk()->assertDontSee(e(route('stock.print', ['q' => 'boulon'])), false);
    }

    public function test_an_outdated_agent_is_named_on_the_lists_never_counted_as_empty(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        [, $old] = $this->register('Vieille', '1.0.6');
        $developer = User::factory()->admin()->create();

        foreach (['/sales/print', '/purchases/print', '/customers/print', '/suppliers/print', '/customers/payments/print'] as $url) {
            $this->assertStringContainsString('Non comptées, agent à mettre à jour : Vieille', $this->actingAs($developer)->get($url)->assertOk()->getContent(), $url);
        }
    }
}
