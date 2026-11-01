<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricesTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Branch $branch, string $code, float $stock, ?float $price): void
    {
        BranchStock::create([
            'branch_id' => $branch->id,
            'code_raw' => $code,
            'code_key' => BranchStock::keyFor($code),
            'designation' => "ARTICLE {$code}",
            'stock' => $stock,
            'price' => $price,
            'seen_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: Branch, 1: Branch, 2: Branch} */
    private function seedBranches(): array
    {
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $setif = Branch::create(['name' => 'Setif', 'active' => true]);
        $alger = Branch::create(['name' => 'Alger', 'active' => true]);
        $this->stock($oran, '820AC', 10, 45);
        $this->stock($setif, '820AC', 164.11, 40);   // prix pas encore mis a jour a Setif
        $this->stock($alger, '820AC', 3, null);      // agent < 1.0.5 : prix inconnu
        $this->stock($oran, '612Z', 5, 12.5);
        $this->stock($setif, '612Z', 7, 12.5);

        return [$oran, $setif, $alger];
    }

    public function test_only_admins_choose_a_single_main_branch(): void
    {
        [$oran, $setif] = $this->seedBranches();
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->create();

        $this->actingAs($worker)->post("/branches/{$oran->id}/main")->assertForbidden();
        $this->assertFalse($oran->fresh()->is_main);

        $this->actingAs($admin)->post("/branches/{$oran->id}/main")->assertSessionHas('status');
        $this->actingAs($admin)->post("/branches/{$setif->id}/main");
        $this->assertSame(['Setif'], Branch::where('is_main', true)->pluck('name')->all());
        $this->actingAs($admin)->get('/branches')->assertSee('إلغاء الرئيسي');

        // second clic : plus de branche principale
        $this->actingAs($admin)->post("/branches/{$setif->id}/main");
        $this->assertSame(0, Branch::where('is_main', true)->count());
    }

    public function test_search_shows_the_main_branch_price_and_flags_other_prices(): void
    {
        [$oran] = $this->seedBranches();
        $worker = User::factory()->create();
        $admin = User::factory()->admin()->create();

        // sans branche principale : pas de colonne prix, un rappel pour l'administrateur
        $this->actingAs($worker)->get('/?q=820')->assertOk()->assertDontSee('سعر مختلف')->assertDontSee('حدّد «الفرع الرئيسي»');
        $this->actingAs($admin)->get('/?q=820')->assertSee('حدّد «الفرع الرئيسي»');
        $this->assertNull($this->actingAs($worker)->get('/?q=820')->viewData('results')[0]->price);

        $oran->forceFill(['is_main' => true])->save();
        $results = $this->actingAs($worker)->get('/?q=article')->assertOk()->assertSee('45,00')->assertSee('سعر مختلف')
            ->viewData('results')->keyBy('code');
        $this->assertEquals(45, $results['820AC']->price);
        $this->assertTrue($results['820AC']->price_differs, 'Setif vend encore a 40');
        $this->assertEquals(12.5, $results['612Z']->price);
        $this->assertFalse($results['612Z']->price_differs);

        // branche principale desactivee : plus de prix de reference
        $oran->update(['active' => false]);
        $this->assertNull($this->actingAs($worker)->get('/?q=820')->viewData('results')[0]->price);
    }

    public function test_preview_lists_every_branch_price_with_the_main_one(): void
    {
        [$oran] = $this->seedBranches();
        $oran->forceFill(['is_main' => true])->save();
        $worker = User::factory()->create();

        $data = $this->actingAs($worker)->getJson('/product?code=820ac')->assertOk()->json();
        $this->assertEquals(45, $data['main_price']);
        $byBranch = collect($data['branches'])->keyBy('branch');
        $this->assertTrue($byBranch['Oran']['main']);
        $this->assertFalse($byBranch['Setif']['main']);
        $this->assertEquals(40, $byBranch['Setif']['rows'][0]['price']);
        $this->assertNull($byBranch['Alger']['rows'][0]['price']);
    }

    public function test_agents_send_the_price_and_older_agents_leave_it_empty(): void
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => 'Oran',
        ])->assertCreated();
        $token = $res->json('token');
        $branch = Branch::findOrFail($res->json('branch_id'));

        $this->withToken($token)->postJson('/api/agent/sync', ['items' => [
            ['code' => '820AC', 'designation' => 'BOULON', 'stock' => 10, 'price' => '45.50'],
            ['code' => '612Z', 'designation' => 'VIS', 'stock' => 3],   // agent 1.0.4 : pas de prix
            ['code' => 'X1', 'designation' => 'X', 'stock' => 1, 'price' => 'n/a'],
        ]])->assertOk();

        $prices = BranchStock::where('branch_id', $branch->id)->pluck('price', 'code_raw');
        $this->assertEquals(45.5, $prices['820AC']);
        $this->assertNull($prices['612Z']);
        $this->assertNull($prices['X1']);

        // "تحديث الآن" met aussi le prix a jour
        $batch = $this->actingAs(User::factory()->create())->postJson('/refresh', ['code' => '820AC'])->json('batch');
        $job = $this->withToken($token)->getJson('/api/agent/pending')->json('jobs.0.id');
        $this->withToken($token)->postJson('/api/agent/result', [
            'job_id' => $job, 'code' => '820AC', 'found' => true,
            'items' => [['code' => '820AC', 'designation' => 'BOULON', 'stock' => 9, 'price' => 47]],
        ])->assertOk();
        $this->assertEquals(47, BranchStock::where('branch_id', $branch->id)->where('code_raw', '820AC')->value('price'));
        $this->assertNotNull($batch);
    }
}
