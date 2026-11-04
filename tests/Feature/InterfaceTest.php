<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchStock;
use App\Models\RefreshJob;
use App\Models\User;
use App\Support\StockState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 2 (docs/roadmap.md) : page d'un article, selecteur de branche, barre Ctrl+K, couleurs du stock de Gescomm. */
class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $oran;

    private Branch $setif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $this->setif = Branch::create(['name' => 'Setif', 'active' => true]);
        BranchAccess::openNewBranch($this->setif);
        $this->oran->forceFill(['is_main' => true])->save();
        $this->stock($this->oran, '820AC', 'BOULLON ACIER 8*20', 120, 550, 400, 380);
        $this->stock($this->setif, '820AC', 'BOULLON ACIER 8*20', 30, 560, 400, 380);
        $this->stock($this->oran, '612Z', 'BOULLON ZINGUEE 6*12', -3);
        $this->stock($this->setif, '1230', 'BOULLON 12*30', 0);
    }

    private function stock(Branch $branch, string $code, string $designation, float $qty, ?float $price = null, ?float $wholesale = null, ?float $buy = null): void
    {
        BranchStock::create(['branch_id' => $branch->id, 'code_raw' => $code, 'code_key' => $code, 'designation' => $designation, 'stock' => $qty,
            'price' => $price, 'price_wholesale' => $wholesale, 'price_buy' => $buy, 'seen_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_product_page_shows_the_shelf_tag_and_each_branch(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/stock/item?code=820ac')->assertOk()
            ->assertSee('class="tag-code ltr">820AC</h1>', false)
            ->assertSee('BOULLON ACIER 8*20')
            ->assertSee('150,00')                       // total des branches
            ->assertSee('550,00')                       // detail de la branche principale, sur l'etiquette
            ->assertSee('فيه مخزون في 2 من 2')
            ->assertSee('--w: 100%', false)->assertSee('--w: 25%', false)   // rayon : 120 et 30
            ->assertSee('class="pill warn num"', false);  // Setif vend a 560, la principale a 550

        // un code inconnu : la raison, et le chemin du retour
        $this->actingAs($admin)->get('/stock/item?code=NOPE')->assertOk()->assertSee('الصنف «NOPE» غير موجود في الفروع المعروضة.');
        // "تحديث الآن" relit seulement le rayon
        $this->actingAs($admin)->get('/stock/item?code=820AC&partial=1')->assertOk()
            ->assertSee('data-done="0"', false)->assertDontSee('tag-code');
    }

    public function test_the_product_page_shows_only_the_prices_and_branches_the_account_may_see(): void
    {
        BranchAccess::set($this->oran->id, 'stock', [], [$this->oran->id, $this->setif->id]);
        $karim = User::factory()->inBranch($this->oran)->withPermissions([])->create();

        $this->actingAs($karim)->get('/stock/item?code=820AC')->assertOk()
            ->assertSee('120,00')->assertDontSee('150,00')
            ->assertDontSee('Setif')
            ->assertDontSee('550,00')->assertDontSee('380,00')
            ->assertDontSee('id="refresh"', false);
    }

    public function test_the_branch_switcher_narrows_every_stock_view_to_one_branch(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$this->oran->id, $this->setif->id]);

        $this->actingAs($owner)->get('/stock?q=820AC')->assertSee('كل فروعي')->assertSee('150,00');
        $this->actingAs($owner)->post('/branch-scope', ['branch_id' => $this->setif->id])->assertRedirect();

        $this->actingAs($owner)->get('/stock?q=820AC')->assertSee('30,00')->assertDontSee('150,00');
        $this->assertSame(['Setif'], array_column($this->actingAs($owner)->getJson('/product?code=820AC')->json('branches'), 'branch'));
        $batch = $this->actingAs($owner)->postJson('/refresh', ['code' => '820AC'])->json('batch');
        $this->assertSame([$this->setif->id], RefreshJob::where('batch', $batch)->pluck('branch_id')->all());
        $this->actingAs($owner)->get('/stock/item?code=820AC')->assertSee('في مخزون Setif')->assertSee('فرع Setif فقط');
        // la principale (Oran) n'est pas affichee : les prix de reference sont ceux de Setif, pas des tirets
        $this->actingAs($owner)->get('/stock/item?code=820AC')->assertSee('560,00');
        $this->actingAs($owner)->get('/stock?q=820AC')->assertSee('560,00');

        // "كل فروعي" : de nouveau toutes ses branches
        $this->actingAs($owner)->post('/branch-scope', ['branch_id' => ''])->assertRedirect();
        $this->actingAs($owner)->get('/stock?q=820AC')->assertSee('150,00');
    }

    public function test_the_switcher_never_opens_a_branch_the_account_does_not_see(): void
    {
        BranchAccess::set($this->oran->id, 'stock', [], [$this->oran->id, $this->setif->id]);
        $karim = User::factory()->inBranch($this->oran)->create();

        $this->actingAs($karim)->post('/branch-scope', ['branch_id' => $this->setif->id])->assertNotFound();
        // une seule branche visible : pas de selecteur
        $this->actingAs($karim)->get('/stock')->assertOk()->assertDontSee('details class="scope', false)->assertDontSee('كل فروعي');

        // branche choisie puis fermee a ce compte : le choix est ignore
        $owner = User::factory()->owner()->create();
        $owner->branches()->attach([$this->oran->id, $this->setif->id]);
        $this->actingAs($owner)->post('/branch-scope', ['branch_id' => $this->setif->id]);
        $owner->branches()->detach($this->setif->id);
        $this->actingAs($owner)->get('/stock?q=820AC')->assertSee('120,00')->assertDontSee('30,00');
    }

    public function test_the_palette_finds_products_without_prices_and_lists_only_allowed_pages(): void
    {
        $karim = User::factory()->inBranch($this->oran)->withPermissions(['prices.buy'])->create();

        $products = $this->actingAs($karim)->getJson('/palette?q=boullon')->assertOk()->json('products');
        $this->assertEqualsCanonicalizing(['820AC', '612Z', '1230'], array_column($products, 'code'));
        $this->assertSame(['code', 'merged', 'designation', 'total', 'state', 'url'], array_keys($products[0]), 'never a price');
        $this->assertSame('negative', collect($products)->firstWhere('code', '612Z')['state']);
        $this->assertSame([], $this->actingAs($karim)->getJson('/palette?q=')->json('products'));

        // les pages de la barre sont en JSON dans la page (les / y sont ecrits \/)
        $inJson = fn (string $route) => trim(json_encode(route($route)), '"');
        $page = $this->actingAs($karim)->get('/stock')->assertOk()->assertSee('id="pal"', false);
        $page->assertSee($inJson('account.edit'), false)->assertDontSee($inJson('users.index'), false)->assertDontSee($inJson('merge.index'), false);
        $this->actingAs(User::factory()->admin()->create())->get('/stock')->assertSee($inJson('users.index'), false);
    }

    public function test_stock_colours_follow_gescomm(): void
    {
        $this->assertSame('zero', StockState::of(0.0));
        $this->assertSame('negative', StockState::of(-132.56));
        $this->assertNull(StockState::of(5.0));
        $this->assertSame('low', StockState::of(5.0, 10.0));
        $this->assertSame('over', StockState::of(500.0, 10.0, 200.0));
        $this->assertNull(StockState::of(null));

        $this->actingAs(User::factory()->admin()->create())->get('/stock?q=boullon')->assertOk()
            ->assertSee('class="qty total negative"', false)
            ->assertSee('class="qty total zero"', false)
            ->assertSee('href="'.route('stock.item', ['code' => '820AC']).'"', false);
    }
}
