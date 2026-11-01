<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\User;
use App\Support\Highlight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Branch $branch, string $code, ?string $designation, float $stock): void
    {
        BranchStock::create([
            'branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => BranchStock::keyFor($code),
            'designation' => $designation, 'stock' => $stock, 'seen_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedStock(): User
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $this->stock($oran, '820AC', 'BOULON 8X20', 10);
        $this->stock($oran, '612Z', 'VIS ZINGUEE 6X12', 3);
        $this->stock($oran, 'B20', 'BOULON 6X12', 4);
        $this->stock($oran, 'AMP1', 'A&B <script>x</script>', 1);

        return User::factory()->create();
    }

    public function test_partial_returns_only_the_results_block(): void
    {
        $user = $this->seedStock();

        $full = $this->actingAs($user)->get('/stock?q=820')->assertOk();
        $this->assertStringContainsString('<html', $full->getContent());
        $this->assertStringContainsString('id="results"', $full->getContent());

        $part = $this->actingAs($user)->get('/stock?q=820&partial=1')->assertOk();
        $this->assertStringNotContainsString('<html', $part->getContent());
        $this->assertStringContainsString('data-code="820AC"', $part->getContent());

        // recherche videe : le message de depart, sans resultat
        $this->actingAs($user)->get('/stock?q=&partial=1')->assertOk()->assertSee('اكتب كود منتج');
    }

    public function test_every_word_must_match_the_code_or_the_designation(): void
    {
        $user = $this->seedStock();
        $codes = fn (string $q) => $this->actingAs($user)->get('/stock?q='.urlencode($q))->viewData('results')->pluck('code')->all();

        // BOULON dans la designation + 20 dans la designation (8X20) ou dans le code (B20)
        $this->assertSame(['820AC', 'B20'], $codes('boulon 20'));
        $this->assertSame(['820AC', 'B20'], $codes('  20   boulon '), 'ordre et espaces sans importance');
        $this->assertSame(['820AC'], $codes('boulon 8x20'));
        $this->assertSame(['612Z'], $codes('vis 6x12'));
        $this->assertSame(['B20', '820AC', 'AMP1'], $codes('b'), 'code qui commence par la recherche, puis designation seule');
        $this->assertSame([], $codes('boulon zinguee'));
    }

    public function test_matches_are_highlighted_without_letting_html_through(): void
    {
        $user = $this->seedStock();

        $this->actingAs($user)->get('/stock?q=boulon+20&partial=1')
            ->assertSee('8<mark>20</mark>AC', false)
            ->assertSee('<mark>BOULON</mark> 8X<mark>20</mark>', false);

        // le mot cherche "amp" ne coupe pas l'entite &amp; et le HTML du texte reste echappe
        $html = $this->actingAs($user)->get('/stock?q=amp&partial=1')->getContent();
        $this->assertStringContainsString('<mark>AMP</mark>1', $html);
        $this->assertStringContainsString('A&amp;B &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);

        $this->assertSame('a<mark>b</mark>c', (string) Highlight::html('abc', ['B']));
        $this->assertSame('&lt;b&gt;', (string) Highlight::html('<b>', []));
    }

    public function test_search_is_rate_limited_per_user(): void
    {
        $user = $this->seedStock();
        for ($i = 0; $i < 240; $i++) {
            $this->actingAs($user)->get('/stock?q=8&partial=1')->assertOk();
        }
        $this->actingAs($user)->get('/stock?q=8&partial=1')->assertStatus(429);
        // un autre compte n'est pas touche
        $this->actingAs(User::factory()->create())->get('/stock?q=8&partial=1')->assertOk();
    }
}
