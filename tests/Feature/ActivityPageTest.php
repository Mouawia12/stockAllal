<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Page "السجل" : le developpeur voit qui a change quoi, en mots, jamais un mot de passe. */
class ActivityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_developer_reads_account_and_branch_changes_in_words(): void
    {
        $dev = User::factory()->admin()->create(['name' => 'Mouawia']);
        $oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $setif = Branch::create(['name' => 'Setif', 'active' => true]);
        BranchAccess::openNewBranch($setif);

        $this->actingAs($dev)->post('/users', ['name' => 'Karim', 'username' => 'karim', 'password' => 'secret-karim-2026', 'role' => 'user', 'branch_id' => $oran->id]);
        $karim = User::where('username', 'karim')->firstOrFail();
        $this->actingAs($dev)->put("/users/{$karim->id}", ['name' => 'Karim', 'username' => 'karim', 'role' => 'branch_manager', 'branch_id' => $oran->id, 'password' => 'another-secret-2026']);
        $this->actingAs($dev)->put("/branches/{$oran->id}/access", ['visible' => []]);

        $this->actingAs($dev)->get('/activity')->assertOk()
            ->assertSee('السجل')
            ->assertSee('أنشأ حساب karim')
            ->assertSee('عدّل حساب karim')
            ->assertSeeInOrder(['نوع الحساب', 'مستخدم', '←', 'مدير الفرع'])
            ->assertSee('كلمة السر')->assertSee('تغيّرت')
            ->assertDontSee('secret-karim-2026')->assertDontSee('another-secret-2026')
            ->assertSee('غيّر ما يراه فرع Oran من مخزون الفروع')
            ->assertSeeInOrder(['يرى مخزون', 'Setif', '←', 'لا شيء'])
            ->assertSee('Mouawia');

        $this->actingAs($dev)->get('/activity?type=branches')->assertOk()->assertSee('غيّر ما يراه فرع Oran')->assertDontSee('أنشأ حساب karim');
        $this->actingAs($dev)->get('/users')->assertSee(route('activity.index'));
    }

    public function test_only_the_developer_opens_it(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/activity')->assertForbidden();
        $this->actingAs($owner)->get('/users')->assertOk()->assertDontSee(route('activity.index'));
    }
}
