<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ProductGroup;
use App\Models\ProductGroupCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Branch $branch, string $code, ?string $designation, ?float $stock): void
    {
        BranchStock::create([
            'branch_id' => $branch->id,
            'code_raw' => $code,
            'code_key' => BranchStock::keyFor($code),
            'designation' => $designation,
            'stock' => $stock,
            'seen_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: Branch, 1: Branch} */
    private function seedBranches(): array
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $setif = Branch::create(['name' => 'Setif', 'active' => true]);
        $this->stock($oran, '820AC', 'BOULON 8X20', 10);
        $this->stock($setif, '820-AC', 'BOULLON ACIER EX 8*20', 164.11);
        $this->stock($setif, '612Z', 'BOULLON ZINGUEE 6*12', -3);

        return [$oran, $setif];
    }

    public function test_merge_page_is_for_admins_only(): void
    {
        $this->seedBranches();
        $worker = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($worker)->get('/stock')->assertDontSee(route('merge.index'));
        $this->actingAs($worker)->get('/merge?q=820')->assertForbidden();
        $this->actingAs($worker)->post('/merge', ['codes' => ['820AC', '820-AC']])->assertForbidden();
        $this->actingAs($worker)->delete('/merge', ['code' => '820AC'])->assertForbidden();
        $this->assertSame(0, ProductGroup::count());

        $this->actingAs($admin)->get('/stock')->assertSee(route('merge.index'));
        $this->actingAs($admin)->get('/merge?q=820')->assertOk()
            ->assertSee('دمج المحدد')
            ->assertSee('name="codes[]" value="820AC"', false)
            ->assertSee('name="codes[]" value="820-AC"', false);
        // la recherche normale n'a pas de cases a cocher
        $this->actingAs($admin)->get('/stock?q=820')->assertOk()->assertDontSee('name="codes[]"', false);
    }

    public function test_merged_codes_are_one_product_in_search_and_preview(): void
    {
        $this->seedBranches();
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->create();

        $this->actingAs($admin)->from('/merge?q=820')->post('/merge', ['codes' => ['820AC', '820-ac']])
            ->assertRedirect('/merge?q=820')->assertSessionHas('status', "تم الدمج: \u{2066}820-AC + 820AC\u{2069}");

        // une seule ligne, sous le premier code trouve par la recherche, avec les quantites des deux branches
        $results = $this->actingAs($worker)->get('/stock?q=820')->assertOk()->assertSee('+ 820AC')->viewData('results');
        $this->assertCount(1, $results);
        $this->assertSame('820-AC', $results[0]->code);
        $this->assertSame(['820AC'], $results[0]->merged);
        $this->assertEquals(174.11, $results[0]->total);
        $this->assertSame(2, $results[0]->branches);
        $this->assertSame('BOULLON ACIER EX 8*20', $results[0]->designation);

        $results = $this->actingAs($worker)->get('/stock?q=820AC')->viewData('results');
        $this->assertSame('820AC', $results[0]->code, 'le code exact reste en tete');
        $this->assertSame(['820-AC'], $results[0]->merged);
        $this->assertEquals(174.11, $results[0]->total);

        // la fenetre "معاينة" montre chaque ecriture dans sa branche
        $data = $this->actingAs($worker)->getJson('/product?code=820ac')->assertOk()->json();
        $this->assertSame('820AC', $data['code']);
        $this->assertSame(['820-AC', '820AC'], $data['codes']);
        $byBranch = collect($data['branches'])->keyBy('branch');
        $this->assertSame('820AC', $byBranch['Oran']['rows'][0]['code']);
        $this->assertSame('820-AC', $byBranch['Setif']['rows'][0]['code']);

        // un code non fusionne ne change pas
        $this->assertSame(['612Z'], $this->actingAs($worker)->getJson('/product?code=612Z')->json('codes'));
    }

    public function test_refresh_asks_every_branch_for_every_code_of_the_group(): void
    {
        [$oran, $setif] = $this->seedBranches();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/merge', ['codes' => ['820AC', '820-AC']]);

        $batch = $this->actingAs($admin)->postJson('/refresh', ['code' => '820-AC'])->assertOk()->json('batch');
        $jobs = RefreshJob::where('batch', $batch)->get();
        $this->assertCount(4, $jobs);
        $this->assertEqualsCanonicalizing(['820-AC', '820AC'], $jobs->where('branch_id', $oran->id)->pluck('code_key')->all());

        $job = fn (Branch $branch, string $code) => $jobs->first(fn ($j) => $j->branch_id == $branch->id && $j->code_key === $code);
        $job($oran, '820AC')->update(['status' => 'done']);
        $job($oran, '820-AC')->update(['status' => 'not_found']);
        $job($setif, '820AC')->update(['status' => 'not_found']);

        // Setif attend encore sa lecture de 820-AC
        $data = $this->actingAs($admin)->getJson("/product?code=820AC&batch={$batch}")->json();
        $byBranch = collect($data['branches'])->keyBy('branch');
        $this->assertSame('done', $byBranch['Oran']['status'], 'une lecture reussie suffit');
        $this->assertSame('pending', $byBranch['Setif']['status']);
        $this->assertFalse($data['done']);

        $job($setif, '820-AC')->update(['status' => 'not_found']);
        $data = $this->actingAs($admin)->getJson("/product?code=820AC&batch={$batch}")->json();
        $this->assertSame('not_found', collect($data['branches'])->keyBy('branch')['Setif']['status']);
        $this->assertTrue($data['done']);
    }

    public function test_merging_joins_groups_and_unmerging_removes_one_code(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/merge', ['codes' => ['820AC', '820-AC']])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/merge', ['codes' => ['820 AC', '612']])->assertSessionHasNoErrors();
        $this->assertSame(2, ProductGroup::count());

        // un code de chaque groupe : les deux groupes n'en font plus qu'un
        $this->actingAs($admin)->post('/merge', ['codes' => ['820-AC', '820 AC']])->assertSessionHasNoErrors();
        $this->assertSame(1, ProductGroup::count());
        $this->assertSame(['612', '820 AC', '820-AC', '820AC'], ProductGroup::codesOf('612'));

        $this->actingAs($admin)->post('/merge', ['codes' => ['820AC', '612']])->assertSessionHasErrors('codes');
        $this->actingAs($admin)->post('/merge', ['codes' => ['820AC', ' 820ac ']])->assertSessionHasErrors('codes');
        $this->actingAs($admin)->post('/merge', [])->assertSessionHasErrors('codes');

        $this->actingAs($admin)->delete('/merge', ['code' => '612'])->assertSessionHas('status');
        $this->assertSame(['612'], ProductGroup::codesOf('612'));
        $this->assertSame(['820 AC', '820-AC', '820AC'], ProductGroup::codesOf('820AC'));

        // un groupe reduit a un seul code disparait
        $this->actingAs($admin)->delete('/merge', ['code' => '820ac']);
        $this->actingAs($admin)->delete('/merge', ['code' => '820-AC']);
        $this->assertSame(0, ProductGroup::count());
        $this->assertSame(0, ProductGroupCode::count());
        $this->assertSame(['820 AC'], ProductGroup::codesOf('820 AC'));
    }
}
