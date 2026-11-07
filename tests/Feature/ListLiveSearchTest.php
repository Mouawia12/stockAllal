<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La recherche pendant la frappe des bons, clients et fournisseurs (partials/live-search) : ?partial=1 rend seulement
 * le bloc sous la recherche, chaque mot doit se trouver (dans n'importe quel ordre, comme le stock), le debut d'un code
 * ou d'un numero suffit, les mots sont mis en evidence, et les liens des pages ne gardent jamais "partial".
 */
class ListLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $oran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true, 'agent_version' => '1.0.9']);
        foreach ([['C001', 'Sarl Atlas Bouira', '0550 11 22 33'], ['C002', 'Ets Benali Ahmed', '0661 44 55 66'], ['K900', 'Quincaillerie El Amel', null]] as [$code, $name, $phone]) {
            Partner::forceCreate(['branch_id' => $this->oran->id, 'kind' => 'customer', 'code' => $code, 'name' => $name, 'phone' => $phone, 'gescomm_key' => $code]);
        }
        Partner::forceCreate(['branch_id' => $this->oran->id, 'kind' => 'supplier', 'code' => 'F10', 'name' => 'Sarl Fournil', 'gescomm_key' => 'F10']);
        foreach ([[1820, 'C001'], [1821, 'C002'], [1822, 'K900'], [905, 'C002']] as $i => [$number, $code]) {
            $this->bon('sale', $number, $code, now()->subDays($i)->toDateString());
        }
        $this->bon('purchase', 77, 'F10', now()->toDateString());
    }

    public function test_the_partial_is_only_the_block_under_the_search_with_the_words_highlighted(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get('/sales?q=ahmed+benali&partial=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('<form class="search"', $html);
        $this->assertStringContainsString('class="chips"', $html);
        $this->assertStringContainsString('<mark>Benali</mark> <mark>Ahmed</mark>', $html);
        $this->assertStringContainsString('>1821<', $html);
        $this->assertStringContainsString('>905<', $html);
        $this->assertStringNotContainsString('>1820<', $html);

        // la page entiere : le formulaire branche la recherche pendant la frappe sur le bloc #results
        $page = $this->actingAs($admin)->get('/sales?q=benali')->assertOk()->getContent();
        $this->assertStringContainsString('data-live="results"', $page);
        $this->assertStringContainsString('<div id="results" data-live-results>', $page);
    }

    public function test_every_word_must_match_and_the_start_of_a_code_or_a_number_is_enough(): void
    {
        $admin = User::factory()->admin()->create();
        $bons = fn (string $q) => array_values(array_filter(array_map(fn ($n) => str_contains(strip_tags(
            $this->actingAs($admin)->get('/sales?partial=1&q='.urlencode($q))->getContent()), (string) $n) ? $n : null, [1820, 1821, 1822, 905])));

        $this->assertSame([1820, 1821, 1822], $bons('182'));
        $this->assertSame([1821, 905], $bons('c002'));
        $this->assertSame([1822], $bons('amel quinc'));
        $this->assertSame([], $bons('atlas amel'));

        $who = fn (string $url) => substr_count($this->actingAs($admin)->get($url)->getContent(), 'class="who"');
        $this->assertSame(1, $who('/customers?partial=1&q=bouira+sarl'));
        $this->assertSame(2, $who('/customers?partial=1&q=c00'));
        $this->assertSame(1, $who('/customers?partial=1&q=0661+44'));
        $this->assertSame(1, $who('/suppliers?partial=1&q=fourn'));
        $this->assertStringContainsString('>77<', $this->actingAs($admin)->get('/purchases?partial=1&q=sarl+fournil')->getContent());
    }

    public function test_the_page_links_of_a_partial_open_the_whole_page(): void
    {
        for ($n = 0; $n < 101; $n++) {
            Partner::forceCreate(['branch_id' => $this->oran->id, 'kind' => 'customer', 'code' => 'Z'.$n, 'name' => 'Client Z'.$n, 'gescomm_key' => 'Z'.$n]);
        }
        $html = $this->actingAs(User::factory()->admin()->create())->get('/customers?partial=1&q=client')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="btn" href="[^"]*page=2/', $html);
        $this->assertStringNotContainsString('partial=1', $html);
    }

    private function bon(string $kind, int $number, string $partner, string $date): void
    {
        Document::forceCreate(['branch_id' => $this->oran->id, 'kind' => $kind, 'number' => $number, 'date' => $date, 'partner_code' => $partner,
            'amount' => 100, 'gescomm_key' => $kind.$number, 'sync_group' => $date]);
    }
}
