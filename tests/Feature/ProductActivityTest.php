<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventes et achats d'un article sur sa page (App\Support\ProductActivity), comme la "Fiche de Stock" de Gescomm :
 * sales.view, sa branche seulement (l'owner : les siennes), montants selon les permissions de prix.
 */
class ProductActivityTest extends TestCase
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
            'stock' => 10, 'seen_at' => now(), 'updated_at' => now()]);

        return [$res->json('token'), $branch];
    }

    /** Le bon 182 de Gescomm : un echange, 25 rendus et 10 vendus ; puis une vente de 4, et un achat de 50. */
    private function history(string $token, int $offset = 0): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'customers', 'groups' => ['p01' => [
            ['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali'],
        ]]])->assertOk();
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [
            now()->subDays(40)->toDateString() => [['key' => (string) (182 + $offset), 'digest' => 2, 'number' => 182 + $offset, 'partner' => 'C001', 'time' => '10:05:00', 'lines' => [
                ['code' => '820Z', 'qty' => -25, 'price' => 300, 'total' => -7500, 'cost' => 250],
                ['code' => '820Z ', 'qty' => 10, 'price' => 310, 'total' => 3100, 'cost' => 250],
                ['code' => 'OTHER', 'qty' => 99, 'price' => 1, 'total' => 99],
            ]]],
            now()->toDateString() => [['key' => (string) (183 + $offset), 'digest' => 3, 'number' => 183 + $offset, 'partner' => 'C999', 'lines' => [
                ['code' => '820z', 'qty' => 4, 'price' => 300, 'total' => 1200, 'cost' => 260],
            ]]],
        ]])->assertOk();
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'purchases', 'groups' => [
            now()->subDays(2)->toDateString() => [['key' => '40|F01', 'digest' => 4, 'number' => 40, 'partner' => 'F01', 'lines' => [
                ['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025],
            ]]],
        ]])->assertOk();
    }

    private function page(User $user, string $query = ''): string
    {
        return $this->actingAs($user)->get('/stock/item?code=820Z'.$query)->assertOk()->getContent();
    }

    public function test_the_fiche_de_stock_of_a_product_for_its_own_branch(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['prices.sale', 'sales.view'])->create();

        $html = $this->page($karim);
        // vendu 14, rendu 25 : net -11 ; montant 4300 - 7500 = -3200 ; prix moyen -3200 / -11
        $this->assertStringContainsString(__('stock.activity.title'), $html);
        foreach (['14,00', '− 25,00', '-11,00', '4 300,00', '− 7 500,00', '-3 200,00', '290,91', '50,00'] as $figure) {
            $this->assertStringContainsString($figure, $html, $figure);
        }
        $this->assertStringContainsString('Ets Benali', $html, 'customer name from GC002');
        $this->assertStringContainsString('C999', $html, 'unknown customer: its code');
        $this->assertStringContainsString(__('stock.activity.return'), $html);
        $this->assertStringNotContainsString('OTHER', $html);
        // pas prices.buy : ni prix d'achat, ni marge
        $this->assertStringNotContainsString('240,50', $html);
        $this->assertStringNotContainsString('12 025,00', $html);
        $this->assertStringNotContainsString(__('stock.activity.margin'), $html);

        // 30 jours : le bon d'il y a 40 jours n'y est plus
        $html = $this->page($karim, '&period=30');
        $this->assertStringContainsString('1 200,00', $html);
        $this->assertStringNotContainsString('− 25,00', $html);
    }

    public function test_without_sales_view_or_prices_the_page_shows_nothing_it_should_not(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);

        $plain = User::factory()->inBranch($oran)->create();   // permissions par defaut : pas sales.view
        $this->assertStringNotContainsString(__('stock.activity.title'), $this->page($plain));
        $this->assertStringNotContainsString('Ets Benali', $this->page($plain));

        // les quantites sans les montants
        $noPrices = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        $html = $this->page($noPrices);
        $this->assertStringContainsString('-11,00', $html);
        foreach (['4 300,00', '1 200,00', '310,00', '290,91', __('stock.activity.col_amount')] as $money) {
            $this->assertStringNotContainsString($money, $html, $money);
        }

        // prices.buy : achats et marge (4300 - 14*250... ligne par ligne : 3100-2500 + 1200-1040 - (-7500+6250))
        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        $html = $this->page($buyer);
        $this->assertStringContainsString('12 025,00', $html);
        $this->assertStringContainsString(__('stock.activity.margin'), $html);
        $this->assertStringContainsString('-490,00', $html);
    }

    public function test_the_owner_sees_each_of_his_branches_and_an_old_agent_is_named(): void
    {
        [$oranToken, $oran] = $this->register('Oran');
        [$setifToken, $setif] = $this->register('Setif');
        [, $blida] = $this->register('Blida', '1.0.6');
        $this->history($oranToken);
        $this->history($setifToken, 1000);
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);

        $html = $this->page($owner);
        $this->assertStringContainsString(__('stock.activity.per_branch'), $html);
        $this->assertStringContainsString('-22,00', $html, 'both branches together');
        $this->assertStringContainsString('1182', $html);
        $this->assertStringContainsString(__('stock.activity.outdated', ['names' => 'Blida']), $html);
    }
}
