<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\InstallCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bons de vente et d'achat (DocumentController), comme la "Consultation des Ventes" de Gescomm : jour par jour,
 * recherche, periode, "avec retour", puis un bon et ses lignes. sales.view ; montants selon les permissions de prix.
 */
class DocumentListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function register(string $name, string $version = '1.0.7'): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => $version,
        ])->assertCreated();
        $branch = Branch::findOrFail($res->json('branch_id'));
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => '820Z', 'code_key' => '820Z', 'designation' => 'BOULON 8*20',
            'stock' => 0, 'seen_at' => now(), 'updated_at' => now()]);

        return [$res->json('token'), $branch];
    }

    /** Aujourd'hui les bons 183 (4 x 300) et 184 (un echange : 2 vendus, 1 rendu) ; il y a 40 jours le bon 182 ; un achat. */
    private function history(string $token): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali', 'phone' => '0550 12 34 56', 'wilaya' => 'Oran'],
        ]]])->assertOk();
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [
            now()->subDays(40)->toDateString() => [['key' => '182', 'digest' => 2, 'number' => 182, 'partner' => 'C001', 'amount' => -4400, 'lines' => [
                ['code' => '820Z', 'qty' => -25, 'price' => 300, 'total' => -7500, 'position' => 1],
                ['code' => '820Z', 'qty' => 10, 'price' => 310, 'total' => 3100, 'position' => 2],
            ]]],
            now()->toDateString() => [
                ['key' => '183', 'digest' => 3, 'number' => 183, 'partner' => 'C999', 'time' => '09:18:33.0000', 'amount' => 1200, 'served_by' => 'Bilal', 'lines' => [
                    ['code' => '820z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 250, 'position' => 1],
                ]],
                ['key' => '184', 'digest' => 4, 'number' => 184, 'partner' => 'C001', 'time' => '10:05:00', 'amount' => 550, 'discount' => 50, 'gross' => 600,
                    'payment' => 'Espéce', 'credit_days' => 15, 'note' => 'echange', 'lines' => [
                        ['code' => '820Z', 'qty' => 2, 'price' => 300, 'discount' => 50, 'total' => 1100, 'cost' => 250, 'position' => 1],
                        ['code' => 'GONE1', 'qty' => -1, 'price' => 550, 'total' => -550, 'position' => 2],
                    ]],
            ],
        ]])->assertOk();
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'purchases', 'groups' => [
            now()->subDays(2)->toDateString() => [['key' => '40|F01', 'digest' => 5, 'number' => 40, 'partner' => 'F01', 'amount' => 12025, 'lines' => [
                ['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025],
            ]]],
        ]])->assertOk();
    }

    private function bon(int $number): Document
    {
        return Document::where('number', $number)->firstOrFail();
    }

    public function test_the_sales_of_its_branch_day_by_day_with_search_period_and_returns(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // 30 jours par defaut : les deux bons d'aujourd'hui, le jour avec son nombre de bons et son montant
        $html = $this->actingAs($karim)->get('/sales')->assertOk()->getContent();
        foreach (['اليوم', 'بونان', '1 750,00', '183', '184', 'Ets Benali', 'C999', '09:18', 'Bilal', 'مرتجع'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('>182<', $html);
        $this->assertStringContainsString(route('sales.show', $this->bon(184)), $html);

        // tout l'historique, puis seulement les bons avec un retour
        $this->assertStringContainsString('>182<', $this->actingAs($karim)->get('/sales?period=all')->getContent());
        $html = $this->actingAs($karim)->get('/sales?period=all&f=returns')->assertOk()->getContent();
        $this->assertStringContainsString('>182<', $html);
        $this->assertStringContainsString('>184<', $html);
        $this->assertStringNotContainsString('>183<', $html);

        // recherche : le nom du client, son code, ou le numero du bon
        $html = $this->actingAs($karim)->get('/sales?period=all&q=benali')->getContent();
        $this->assertStringContainsString('>182<', $html);
        $this->assertStringNotContainsString('>183<', $html);
        $html = $this->actingAs($karim)->get('/sales?q=c999')->getContent();
        $this->assertStringContainsString('>183<', $html);
        $this->assertStringNotContainsString('>184<', $html);
        $html = $this->actingAs($karim)->get('/sales?q=184')->getContent();
        $this->assertStringContainsString('>184<', $html);
        $this->assertStringNotContainsString('>183<', $html);
        $this->actingAs($karim)->get('/sales?q=nobody')->assertOk()->assertSee(__('documents.none_search', ['q' => 'nobody']));

        // le bon : le client, ses lignes, le retour, le stock actuel (rouge : 0) et les totaux
        $html = $this->actingAs($karim)->get(route('sales.show', $this->bon(184)))->assertOk()->getContent();
        foreach (['Ets Benali', '0550 12 34 56', 'Espéce', '15 يوماً', 'echange', 'BOULON 8*20', 'GONE1', '1 100,00', '600,00', '550,00'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString(route('stock.item', ['code' => '820Z']), $html);
        $this->assertMatchesRegularExpression('/class="qty zero">0,00</', $html);
        $this->assertStringContainsString(trans_choice('documents.return_lines', 1, ['count' => 1]), $html);
        // pas prices.buy : pas de marge
        $this->assertStringNotContainsString(__('stock.activity.margin'), $html);

        // un achat ne s'ouvre pas comme une vente
        $this->actingAs($karim)->get('/sales/'.$this->bon(40)->id)->assertNotFound();
        $this->actingAs($karim)->get('/purchases/'.$this->bon(40)->id)->assertOk()->assertSee('F01');

        // la page d'un article mene a ses bons
        $this->assertStringContainsString(route('sales.show', $this->bon(183)), $this->actingAs($karim)->get('/stock/item?code=820Z')->getContent());
    }

    public function test_without_sales_view_or_prices_the_bons_show_nothing_they_should_not(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $bon = $this->bon(184);

        // permissions par defaut : pas sales.view
        $plain = User::factory()->inBranch($oran)->create();
        $this->actingAs($plain)->get('/sales')->assertForbidden();
        $this->actingAs($plain)->get('/purchases')->assertForbidden();
        $this->actingAs($plain)->get(route('sales.show', $bon))->assertForbidden();

        // les bons sans les montants
        $noPrices = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->actingAs($noPrices)->get('/sales')->assertOk()->getContent();
        $this->assertStringContainsString('>184<', $html);
        foreach (['1 750,00', '1 200,00', __('documents.col_amount')] as $money) {
            $this->assertStringNotContainsString($money, $html, $money);
        }
        $html = $this->actingAs($noPrices)->get(route('sales.show', $bon))->assertOk()->getContent();
        $this->assertStringContainsString('2,00', $html);
        foreach (['1 100,00', '550,00', __('documents.amount')] as $money) {
            $this->assertStringNotContainsString($money, $html, $money);
        }

        // prices.sale ne donne pas les montants d'achat
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->assertStringNotContainsString('12 025,00', $this->actingAs($seller)->get('/purchases')->getContent());

        // prices.buy : les montants d'achat et la marge d'un bon (1200 - 4 x 250)
        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        $this->actingAs($buyer)->get('/purchases')->assertOk()->assertSee('12 025,00');
        $html = $this->actingAs($buyer)->get(route('sales.show', $this->bon(183)))->assertOk()->assertSee(__('stock.activity.margin'))->getContent();
        $this->assertStringContainsString('<dd class="num">200,00</dd>', $html);
    }

    public function test_the_owner_sees_each_branch_in_its_colour_and_an_old_agent_is_named(): void
    {
        [$oranToken, $oran] = $this->register('Oran');
        [$setifToken, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida', '1.0.6');
        $this->history($oranToken);
        $this->withToken($setifToken)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [now()->toDateString() => [
            ['key' => '9001', 'digest' => 9, 'number' => 9001, 'amount' => 100, 'lines' => [['code' => '820Z', 'qty' => 1, 'total' => 100]]],
        ]]])->assertOk();
        $owner = User::factory()->owner()->withPermissions(['sales.view'])->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);

        $html = $this->actingAs($owner)->get('/sales')->assertOk()->getContent();
        $this->assertStringContainsString('>9001<', $html);
        $this->assertStringContainsString('>183<', $html);
        $this->assertMatchesRegularExpression('/<tr class="bt"\s+style="--bh: '.$setif->hue().'"/', $html);
        $this->assertStringContainsString(__('documents.outdated', ['names' => 'Blida']), $html);

        // une seule branche choisie en haut : ses bons seulement, sans colonne des branches
        $this->actingAs($owner)->post('/branch-scope', ['branch_id' => $setif->id]);
        $html = $this->actingAs($owner)->get('/sales')->assertOk()->getContent();
        $this->assertStringContainsString('>9001<', $html);
        $this->assertStringNotContainsString('>183<', $html);
        $this->assertStringNotContainsString('class="bt"', $html);
    }
}
