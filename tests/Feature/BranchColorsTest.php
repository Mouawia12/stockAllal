<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Chaque branche a sa couleur legere (Branch::hue) : ses lignes sur la page d'un article, dans l'apercu, et sa pastille. */
class BranchColorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_branch_has_its_own_colour_never_red_or_yellow(): void
    {
        $hues = array_map(fn (int $id) => Branch::hueFor($id), range(1, count(Branch::HUES)));
        $this->assertCount(count($hues), array_unique($hues), 'une couleur par branche');
        foreach ($hues as $h) {
            // rouge = stock 0, jaune = stock negatif (StockState)
            $this->assertFalse($h < 20 || $h > 340 || ($h >= 40 && $h <= 70), "teinte $h : rouge ou jaune");
        }
        $this->assertSame(Branch::hueFor(1), Branch::hueFor(1 + count(Branch::HUES)), 'apres la derniere couleur, on recommence');
    }

    public function test_the_rows_of_each_branch_carry_its_colour(): void
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $setif = Branch::create(['name' => 'Setif', 'active' => true]);
        foreach ([$oran, $setif] as $b) {
            BranchStock::create(['branch_id' => $b->id, 'code_raw' => '820AC', 'code_key' => '820AC', 'designation' => 'BOULLON',
                'stock' => 5, 'seen_at' => now(), 'updated_at' => now()]);
        }
        $this->assertNotSame($oran->hue(), $setif->hue());
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/stock/item?code=820AC')->assertOk()->getContent();
        foreach ([$oran, $setif] as $b) {
            $this->assertStringContainsString('class="bt" style="--bh: '.$b->hue().'"', $html, $b->name);
        }

        // la fenetre "معاينة" colore ses lignes avec la teinte du JSON
        $data = $this->actingAs($user)->getJson('/product?code=820AC')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$oran->hue(), $setif->hue()], array_column($data['branches'], 'hue'));

        // la pastille sur la page des branches
        $this->actingAs(User::factory()->admin()->create())->get('/branches')->assertOk()
            ->assertSee('class="bdot" style="--bh: '.$setif->hue(), false);
    }
}
