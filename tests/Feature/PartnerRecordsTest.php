<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\InstallCode;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La fenetre de la fiche d'un client ou d'un fournisseur (PartnerController::records) : une page a la fois, recherche,
 * filtre, tri, un article bon par bon. Memes branches et memes montants que la fiche.
 */
class PartnerRecordsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Branch} */
    private function branch(string $name): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => '1.0.8',
        ])->assertCreated();
        $branch = Branch::findOrFail($res->json('branch_id'));
        foreach (['820Z' => 'BOULON 8*20', 'VIS5' => 'VIS A BOIS 5*40'] as $code => $name) {
            BranchStock::create(['branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => $code, 'designation' => $name, 'stock' => 0, 'seen_at' => now(), 'updated_at' => now()]);
        }

        return [$res->json('token'), $branch];
    }

    private function send(string $token, string $dataset, array $groups): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $groups])->assertOk();
    }

    /**
     * Benali : 30 bons, un par jour, de 1000 + i ; le dernier (n° 1030) a un retour, les autres prennent 820Z, certains VIS5.
     * 3 versements : 500 en especes (cheque CH-77), 700 "Autre" sur le bon 1030, 900 en especes avec une note.
     */
    private function benali(string $token): Partner
    {
        $this->send($token, 'customers', ['p1' => [['key' => 'C001', 'digest' => 1, 'code' => 'C001', 'name' => 'Ets Benali']]]);
        $groups = [];
        for ($i = 1; $i <= 30; $i++) {
            $day = now()->subDays(30 - $i)->toDateString();
            $lines = [['code' => '820Z', 'qty' => 2, 'price' => 100, 'total' => 200, 'cost' => 80]];
            if ($i % 3 === 0) {
                $lines[] = ['code' => 'VIS5', 'qty' => 1, 'price' => 800 + $i, 'total' => 800 + $i, 'cost' => 500];
            }
            if ($i === 30) {
                $lines[] = ['code' => '820Z', 'qty' => -1, 'price' => 130, 'total' => -130, 'cost' => 80];
            }
            $groups[$day] = [['key' => (string) (1000 + $i), 'digest' => 100 + $i, 'number' => 1000 + $i, 'partner' => 'C001', 'amount' => 1000 + $i, 'time' => '09:30:00.0000',
                'note' => $i === 7 ? 'Chantier Maraval' : null, 'lines' => $lines]];
        }
        $this->send($token, 'sales', $groups);
        $this->send($token, 'customer_payments', [
            now()->subDays(9)->toDateString() => [['key' => 'a|1', 'digest' => 1, 'number' => 1, 'partner' => 'C001', 'type' => 'Espéce', 'amount' => 500, 'cheque' => 'CH-77']],
            now()->subDays(5)->toDateString() => [['key' => 'b|2', 'digest' => 2, 'number' => 2, 'partner' => 'C001', 'type' => 'Autre', 'amount' => 700, 'document_number' => 1030]],
            now()->subDays(1)->toDateString() => [['key' => 'c|3', 'digest' => 3, 'number' => 3, 'partner' => 'C001', 'type' => 'Espéce', 'amount' => 900, 'note' => 'Reste du mois']],
        ]);

        return Partner::where('code', 'C001')->where('branch_id', Branch::where('name', 'Oran')->value('id'))->firstOrFail();
    }

    private function records(User $user, Partner $partner, array $query = []): string
    {
        return $this->actingAs($user)->get(route('customers.records', $partner).'?'.http_build_query($query))->assertOk()->getContent();
    }

    private function rowsIn(string $html): int
    {
        return substr_count($html, 'data-row');
    }

    public function test_one_page_at_a_time_newest_first_with_the_place_in_the_list(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        $html = $this->records($karim, $benali);
        $this->assertSame(25, $this->rowsIn($html));
        foreach (['data-page="1"', 'data-pages="2"', 'data-total="30"', __('partners.records.range', ['from' => 1, 'to' => 25, 'total' => 30])] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertLessThan(strpos($html, '>1029<'), strpos($html, '>1030<'), 'le plus recent d\'abord');
        $this->assertStringNotContainsString('>1005<', $html);

        $html = $this->records($karim, $benali, ['page' => 2]);
        $this->assertSame(5, $this->rowsIn($html));
        $this->assertStringContainsString('>1005<', $html);
        $this->assertStringNotContainsString('>1006<', $html);

        // une taille reconnue, sinon celle par defaut
        $this->assertSame(30, $this->rowsIn($this->records($karim, $benali, ['per' => 50])));
        $this->assertSame(25, $this->rowsIn($this->records($karim, $benali, ['per' => 100000])));

        // le total de toute la liste, pas de la page
        $this->assertStringContainsString(number_format(array_sum(array_map(fn ($i) => 1000 + $i, range(1, 30))), 2, ',', ' '), $this->records($karim, $benali));
    }

    public function test_search_filter_and_sort(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // les bons : par numero ou par note, plusieurs mots
        $this->assertSame(1, $this->rowsIn($this->records($karim, $benali, ['q' => '1017'])));
        $html = $this->records($karim, $benali, ['q' => 'chantier maraval']);
        $this->assertSame(1, $this->rowsIn($html));
        $this->assertStringContainsString('>1007<', $html);
        $this->assertStringContainsString(__('partners.records.none_search', ['q' => 'introuvable']), $this->records($karim, $benali, ['q' => 'introuvable']));
        // un % cherche un %, il ne remplit pas la liste
        $this->assertSame(0, $this->rowsIn($this->records($karim, $benali, ['q' => '%'])));

        // ceux qui ont un retour
        $html = $this->records($karim, $benali, ['f' => 'returns']);
        $this->assertSame(1, $this->rowsIn($html));
        $this->assertStringContainsString('>1030<', $html);

        // le tri : une colonne connue, dans un sens ou l'autre
        $html = $this->records($karim, $benali, ['sort' => 'amount', 'dir' => 'asc']);
        $this->assertLessThan(strpos($html, '>1002<'), strpos($html, '>1001<'));
        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $html = $this->records($karim, $benali, ['sort' => 'number; DROP TABLE documents', 'dir' => 'sideways']);
        $this->assertLessThan(strpos($html, '>1029<'), strpos($html, '>1030<'));

        // les versements : par type (comme Gescomm les ecrit), note, cheque, montant, numero du bon
        $this->assertSame(3, $this->rowsIn($this->records($karim, $benali, ['tab' => 'payments'])));
        $html = $this->records($karim, $benali, ['tab' => 'payments', 'f' => 'Autre']);
        $this->assertSame(1, $this->rowsIn($html));
        $this->assertStringContainsString('700,00', $html);
        foreach (['ch-77' => '500,00', 'reste du mois' => '900,00', '700' => '700,00', '1030' => '700,00'] as $q => $found) {
            $html = $this->records($karim, $benali, ['tab' => 'payments', 'q' => $q]);
            $this->assertSame(1, $this->rowsIn($html), $q);
            $this->assertStringContainsString($found, $html, $q);
        }
        // un filtre inconnu est ignore
        $this->assertSame(3, $this->rowsIn($this->records($karim, $benali, ['tab' => 'payments', 'f' => "x' OR 1=1"])));

        // les articles : par code ou par designation
        $this->assertSame(2, $this->rowsIn($this->records($karim, $benali, ['tab' => 'items'])));
        $this->assertSame(1, $this->rowsIn($this->records($karim, $benali, ['tab' => 'items', 'q' => 'vis5'])));
        $html = $this->records($karim, $benali, ['tab' => 'items', 'q' => 'boulon 8*20']);
        $this->assertSame(1, $this->rowsIn($html));
        $this->assertStringContainsString('820Z', $html);
    }

    public function test_an_item_line_by_line_with_what_was_sold_returned_and_the_last_price(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();

        // 820Z : 30 lignes de 2 a 100, un retour de 1 ; vendu 60, retourne 1, net 59
        $html = $this->records($karim, $benali, ['tab' => 'items', 'sort' => 'qty']);
        foreach (['BOULON 8*20', 'data-code="820Z"', 'data-code="VIS5"'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertLessThan(strpos($html, 'data-code="VIS5"'), strpos($html, 'data-code="820Z"'), 'le plus pris d\'abord');

        $html = $this->records($karim, $benali, ['tab' => 'lines', 'code' => '820z', 'per' => 50]);
        $this->assertSame(31, $this->rowsIn($html));
        $this->assertStringContainsString('data-title="820Z"', $html);
        $this->assertStringContainsString('data-sub="BOULON 8*20"', $html);
        $this->assertStringContainsString(e(route('stock.item', ['code' => '820Z'])), $html);
        $this->assertStringContainsString(__('documents.return'), $html);
        $this->assertStringContainsString('>59<', $html, 'le net');
        $this->assertStringContainsString('100,00', $html, 'le dernier prix : pas celui du retour');
        $this->assertStringContainsString(route('sales.show', Document::where('number', 1030)->first()), $html);

        // un article qu'il n'a jamais pris
        $this->assertStringContainsString(__('partners.records.none_item'), $this->records($karim, $benali, ['tab' => 'lines', 'code' => 'NOPE']));
    }

    public function test_no_amount_without_prices_not_even_through_the_order_or_the_search(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        $plain = User::factory()->inBranch($oran)->withPermissions(['sales.view'])->create();

        $html = $this->records($plain, $benali);
        $this->assertStringContainsString('>1030<', $html);
        foreach (['1 030,00', '1 001,00', __('documents.col_amount')] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        // trier par montant reviendrait a le montrer : la demande est ignoree
        $html = $this->records($plain, $benali, ['sort' => 'amount', 'dir' => 'asc']);
        $this->assertLessThan(strpos($html, '>1029<'), strpos($html, '>1030<'), 'toujours par date');

        // chercher un montant reviendrait au meme
        $html = $this->records($plain, $benali, ['tab' => 'payments']);
        $this->assertSame(3, $this->rowsIn($html));
        foreach (['500,00', '700,00', '900,00'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        $this->assertSame(0, $this->rowsIn($this->records($plain, $benali, ['tab' => 'payments', 'q' => '900'])));

        foreach (['tab=items', 'tab=items&sort=amount', 'tab=lines&code=820Z', 'tab=lines&code=820Z&sort=total'] as $query) {
            $html = $this->actingAs($plain)->get(route('customers.records', $benali).'?'.$query)->assertOk()->getContent();
            foreach (['100,00', '130,00', __('documents.col_price'), __('partners.records.sum_last_price')] as $hidden) {
                $this->assertStringNotContainsString($hidden, $html, $query.' '.$hidden);
            }
        }
    }

    public function test_a_card_of_a_branch_the_account_does_not_see_does_not_exist(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        [, $setif] = $this->branch('Setif');

        $outside = User::factory()->inBranch($setif)->withPermissions(['sales.view', 'prices.sale'])->create();
        foreach (['bons', 'payments', 'items', 'lines'] as $tab) {
            $this->actingAs($outside)->get(route('customers.records', $benali).'?tab='.$tab.'&code=820Z')->assertNotFound();
        }

        $noSales = User::factory()->inBranch($oran)->create();
        $this->actingAs($noSales)->get(route('customers.records', $benali))->assertForbidden();

        $this->send($token, 'suppliers', ['p2' => [['key' => 'F01', 'digest' => 1, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $this->actingAs($karim)->get(route('customers.records', Partner::where('code', 'F01')->first()))->assertNotFound();

        auth()->logout();
        $this->get(route('customers.records', $benali))->assertRedirect('/login');
    }

    public function test_the_stock_of_an_item_only_if_the_account_sees_that_stock(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $benali = $this->benali($token);
        [, $setif] = $this->branch('Setif');
        BranchStock::where('branch_id', $oran->id)->where('code_key', '820Z')->update(['stock' => -7]);

        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->records($karim, $benali, ['tab' => 'items']);
        $this->assertStringContainsString('qty negative', $html);
        $this->assertStringContainsString('>-7<', $html);

        // Setif voit les ventes d'Oran, pas son stock
        BranchAccess::set($setif->id, 'sales', [$oran->id], [$oran->id, $setif->id]);
        BranchAccess::set($setif->id, 'stock', [], [$oran->id, $setif->id]);
        $guest = User::factory()->inBranch($setif)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->records($guest, $benali, ['tab' => 'items']);
        $this->assertStringContainsString('BOULON 8*20', $html);
        $this->assertStringNotContainsString('>-7<', $html);
        $this->assertStringNotContainsString(__('documents.col_stock'), $html);
    }

    public function test_a_supplier_window_uses_the_purchase_prices(): void
    {
        [$token, $oran] = $this->branch('Oran');
        $this->send($token, 'suppliers', ['p2' => [['key' => 'F01', 'digest' => 1, 'code' => 'F01', 'name' => 'Sarl Fournil']]]);
        $this->send($token, 'purchases', [now()->subDay()->toDateString() => [['key' => '40|F01', 'digest' => 2, 'number' => 40, 'partner' => 'F01', 'amount' => 12025,
            'lines' => [['code' => '820Z', 'qty' => 50, 'price' => 240.5, 'total' => 12025]]]]]);
        $fournil = Partner::where('code', 'F01')->firstOrFail();
        $bon = route('purchases.show', Document::where('number', 40)->first());

        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $html = $this->actingAs($seller)->get(route('suppliers.records', $fournil))->assertOk()->getContent();
        $this->assertStringContainsString($bon, $html);
        $this->assertStringNotContainsString('12 025,00', $html);

        $buyer = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.buy'])->create();
        $this->actingAs($buyer)->get(route('suppliers.records', $fournil))->assertOk()->assertSee('12 025,00');
    }
}
