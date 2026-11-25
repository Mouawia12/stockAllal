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

    /** La fenetre F9 de la recherche (stock/_card) : un morceau de page. */
    private function card(User $user, string $query = ''): string
    {
        $html = $this->actingAs($user)->get('/stock/card?code=820Z'.$query)->assertOk()->getContent();
        $this->assertStringNotContainsString('<html', $html);

        return $html;
    }

    public function test_f9_in_the_search_opens_the_stock_card_who_bought_when_and_at_what_price(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();

        // la recherche annonce la touche et porte la fenetre
        $html = $this->actingAs($boss)->get('/stock?q=820')->assertOk()->getContent();
        foreach (['id="card-bg"', '<kbd>F9</kbd>', e(__('stock.card.key')), json_encode(route('stock.card'))] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        // la fiche : stock 10, achete 50 (12 025), vendu net -11 (-3 200), prix moyen 290,91 ; puis les ventes, la plus
        // recente d'abord : le bon 183 d'aujourd'hui, puis le 182 a 10:05:00 (10 vendus a 310, 25 rendus a 300)
        $html = $this->card($boss);
        foreach (['BOULON 8*20', e(__('stock.card.facts.stock')), '>10<', '>50<', '12 025,00', '>-11<', '-3 200,00', '290,91', e(__('stock.card.everyone.sale'))] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertMatchesRegularExpression('/'.preg_quote(now()->format('d/m/Y'), '/').'.*>4<.*C999.*>183<.*'.preg_quote(now()->subDays(40)->format('d/m/Y'), '/')
            .'.*10:05:00.*>10<.*310,00.*Ets Benali.*>182<.*'.preg_quote(e(__('stock.activity.return')), '/').'.*>-25<.*300,00/s', $html);
        $this->assertStringNotContainsString('240,50', $html);
        $this->assertStringNotContainsString('OTHER', $html);

        // l'onglet des achats : le bon 40, 50 a 240,50, sans les ventes
        $html = $this->card($boss, '&tab=purchase');
        $this->assertMatchesRegularExpression('/'.preg_quote(now()->subDays(2)->format('d/m/Y'), '/').'.*>50<.*240,50.*F01.*>40</s', $html);
        $this->assertStringContainsString(e(__('stock.card.who.purchase')), $html);
        $this->assertStringNotContainsString('Ets Benali', $html);

        // un client : chaque mot dans son nom, ou le debut de son code ; ce qu'il en a pris (10 sortis, 25 rendus, net -15)
        $html = $this->card($boss, '&who=benali+ets');
        $this->assertMatchesRegularExpression('/'.preg_quote(e(__('stock.card.for', ['who' => 'benali ets'])), '/').'.*>-15<.*>10<.*>25</s', $html);
        $this->assertStringContainsString('Ets Benali', $html);
        $this->assertStringNotContainsString('>183<', $html);
        $html = $this->card($boss, '&who=c99');
        $this->assertStringContainsString('>183<', $html);
        $this->assertStringNotContainsString('>182<', $html);
        $this->assertStringContainsString(e(__('stock.card.none', ['who' => 'zzz'])), $this->card($boss, '&who=zzz'));

        // un article inconnu n'existe pas
        $this->actingAs($boss)->get('/stock/card?code=NOPE')->assertNotFound();
    }

    public function test_the_stock_card_shows_the_latest_lines_then_all_of_them(): void
    {
        [$token, $oran] = $this->register('Oran');
        $this->history($token);
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => 'sales', 'groups' => [
            now()->subDays(3)->toDateString() => [['key' => '500', 'digest' => 9, 'number' => 500, 'partner' => 'C001', 'time' => '09:00:00',
                'lines' => array_map(fn (int $i) => ['code' => '820Z', 'qty' => 1, 'price' => 300, 'total' => 300, 'position' => $i], range(1, 105))]],
        ]])->assertOk();
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        $html = $this->card($boss);
        $this->assertStringContainsString(e(__('stock.activity.latest', ['shown' => '100', 'total' => '108'])), $html);
        $this->assertStringContainsString('data-all', $html);
        $this->assertSame(100, substr_count($html, '<tr class="bt'));
        $html = $this->card($boss, '&all=1');
        $this->assertSame(108, substr_count($html, '<tr class="bt'));
        $this->assertStringNotContainsString('data-all', $html);
    }

    public function test_the_stock_card_needs_sales_view_and_hides_what_the_account_may_not_see(): void
    {
        [$token, $oran] = $this->register('Oran');
        [, $setif] = $this->register('Setif');
        $this->history($token);

        // sans "المبيعات" : ni touche, ni fenetre, ni fiche
        $plain = User::factory()->inBranch($oran)->create();
        $html = $this->actingAs($plain)->get('/stock?q=820')->assertOk()->getContent();
        foreach (['id="card-bg"', '<kbd>F9</kbd>', 'id="m-card"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->actingAs($plain)->get('/stock/card?code=820Z')->assertForbidden();

        // les quantites sans les montants, a l'ecran des ventes comme des achats
        $noPrices = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();
        foreach (['', '&tab=purchase'] as $tab) {
            $html = $this->card($noPrices, $tab);
            $this->assertStringContainsString('>-11<', $html);
            foreach (['-3 200,00', '310,00', '300,00', '290,91', '12 025,00', '240,50', __('stock.card.facts.sold_amount'), __('stock.card.facts.price_buy'), __('stock.activity.col_price')] as $money) {
                $this->assertStringNotContainsString(e($money), $html, $tab.' '.$money);
            }
        }
        // prix de vente sans prix d'achat : les ventes en montants, les achats en quantites
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->assertStringContainsString('-3 200,00', $this->card($seller));
        $html = $this->card($seller, '&tab=purchase');
        $this->assertStringContainsString('>50<', $html);
        foreach (['240,50', '12 025,00'] as $money) {
            $this->assertStringNotContainsString($money, $html, $money);
        }

        // un compte de Setif voit le stock d'Oran (ouvert par defaut), jamais ses ventes
        $salim = User::factory()->inBranch($setif)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        foreach (['', '&tab=purchase', '&who=benali'] as $query) {
            $html = $this->card($salim, $query);
            foreach (['Ets Benali', '>183<', '>182<', '10:05:00', '240,50', '-3 200,00'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, $query.' '.$secret);
            }
        }
        $this->assertStringContainsString(e(__('stock.activity.none_sale')), $this->card($salim));
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
