<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InstallCode;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La caisse (agent 1.0.8, CashBox) : entrees C008 et sorties C002 ligne par ligne, solde = entrees - sorties comme
 * MONTANTCAISSE de Gescomm, sur "لوحة القيادة" pour qui a cash.view, dans les branches dont il voit les ventes.
 */
class CashBoxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 11:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: Branch} */
    private function register(string $name, string $version = '1.0.8'): array
    {
        $res = $this->postJson('/api/agents/register', [
            'install_code' => InstallCode::generate(null), 'branch_name' => $name, 'hostname' => 'PC-'.$name, 'agent_version' => $version,
        ])->assertCreated();

        return [$res->json('token'), Branch::findOrFail($res->json('branch_id'))];
    }

    private function cash(string $token, string $dataset, array $days): void
    {
        $this->withToken($token)->postJson('/api/agent/records', ['dataset' => $dataset, 'groups' => $days])->assertOk();
    }

    /** Une ligne de caisse comme l'agent l'envoie (texte d'isql) ; la cle = jour|ORDERE. */
    private function line(string $day, int $order, string $time, string $amount, array $more = []): array
    {
        return ['key' => $day.'|'.$order, 'digest' => $order * 1000 + (int) $amount, 'number' => (string) $order,
            'time' => $time.'.0000', 'amount' => $amount, 'entered_by' => 'ADMINISTRATEUR'] + $more;
    }

    /** Oran : 3 entrees (2 avant aujourd'hui) et 2 sorties (une aujourd'hui) ; solde 1 099 975,54 comme Gescomm le 05/11. */
    private function oran(): array
    {
        [$token, $oran] = $this->register('Oran');
        $this->cash($token, 'cash_in', [
            '2026-06-23' => [$this->line('2026-06-23', 1, '09:00:00', '84137398.07', ['label' => 'Client: OTHMANI', 'payment_number' => '13', 'counter' => '0'])],
            '2026-11-04' => [],
            '2026-11-05' => [
                $this->line('2026-11-05', 1251, '10:07:51', '436745.00', ['label' => 'Client: SARL MBHA', 'payment_number' => '1535', 'counter' => '0']),
                $this->line('2026-11-05', 1252, '10:47:00', '66000.00', ['label' => 'Client: SARL BRAHIMI', 'payment_number' => '1536', 'counter' => '0']),
            ],
        ]);
        $this->cash($token, 'cash_out', [
            '2026-11-04' => [$this->line('2026-11-04', 188, '15:39:51', '82740167.53', ['label' => 'Fourn: TPL EL HAMIZ', 'expense' => 'N', 'payment_number' => '191'])],
            '2026-11-05' => [$this->line('2026-11-05', 189, '10:51:46', '800000.00', ['label' => 'Fourn: TPL EL HAMIZ', 'note' => 'MAA OUSSAMA', 'expense' => 'N'])],
        ]);

        return [$token, $oran];
    }

    public function test_the_agent_lines_are_kept_as_gescomm_has_them(): void
    {
        [$token, $oran] = $this->oran();

        $in = DB::table('cash_entries')->where('direction', 'in')->where('number', 1252)->first();
        $this->assertSame(['2026-11-05', '10:47:00', 66000.0, 'Client: SARL BRAHIMI', 1536, 0, '2026-11-05|1252'],
            [Carbon::parse($in->date)->toDateString(), $in->time, (float) $in->amount, $in->label, (int) $in->payment_number, (int) $in->counter, $in->gescomm_key]);
        $out = DB::table('cash_entries')->where('direction', 'out')->where('number', 189)->first();
        $this->assertSame(['Fourn: TPL EL HAMIZ', 'MAA OUSSAMA', 0], [$out->label, $out->note, (int) $out->expense]);

        // une depense (UNECHARGE = O) ; les sorties renvoyees ne touchent pas aux entrees du meme jour
        $this->cash($token, 'cash_out', ['2026-11-05' => [
            $this->line('2026-11-05', 189, '10:51:46', '800000.00', ['label' => 'Fourn: TPL EL HAMIZ', 'note' => 'MAA OUSSAMA', 'expense' => 'N']),
            $this->line('2026-11-05', 190, '10:55:00', '1500.00', ['category' => 'MASARIFE HANOUT', 'category_code' => 'MH', 'expense' => 'O']),
        ]]);
        $this->assertSame(1, (int) DB::table('cash_entries')->where('category_code', 'MH')->value('expense'));
        $this->assertSame([3, 3], [DB::table('cash_entries')->where('direction', 'in')->count(), DB::table('cash_entries')->where('direction', 'out')->count()]);
        $this->assertSame($oran->id, (int) DB::table('cash_entries')->value('branch_id'));
    }

    public function test_the_balance_is_everything_in_minus_everything_out(): void
    {
        [$token, $oran] = $this->oran();
        $boss =User::factory()->inBranch($oran)->withPermissions(['sales.view', 'cash.view'])->create();

        // 84 137 398,07 + 436 745 + 66 000 - 82 740 167,53 - 800 000 ; aujourd'hui 502 745 entres, 800 000 sortis
        $html = $this->actingAs($boss)->get('/dashboard?period=today')->assertOk()->getContent();
        foreach ([__('dashboard.cash.now'), '1 099 975,54', __('dashboard.cash.in_today'), '502 745,00', '800 000,00',
            __('dashboard.cash.last', ['when' => "\u{2066}10:51\u{2069}"]), __('dashboard.cash.checked', ['time' => now()->diffForHumans()])] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }

        // 30 jours : le meme solde, et ce qui est entre et sorti pendant ces 30 jours (pas la sortie du 23/06)
        $html = $this->actingAs($boss)->get('/dashboard')->assertOk()->getContent();
        foreach (['1 099 975,54', __('dashboard.cash.in_period'), '502 745,00', '83 540 167,53'] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }

        // un jour passe, choisi dans le calendrier : la caisse a la fin de ce jour, comme ETAT_CAISSE3 de Gescomm
        $html = $this->actingAs($boss)->get('/dashboard?day=2026-11-04')->assertOk()->getContent();
        foreach ([__('dashboard.cash.at_end', ['date' => "\u{2066}04/11/2026\u{2069}"]), '1 397 230,54', __('dashboard.cash.out_day'), '82 740 167,53',
            __('dashboard.cash.last', ['when' => "\u{2066}04/11 15:39\u{2069}"])] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('1 099 975,54', $html);

        // une sortie de plus dans Gescomm : le solde suit (apres le cache de 60 s)
        $this->cash($token, 'cash_out', ['2026-11-05' => [
            $this->line('2026-11-05', 189, '10:51:46', '800000.00', ['label' => 'Fourn: TPL EL HAMIZ']),
            $this->line('2026-11-05', 190, '10:58:00', '99975.54', ['category' => 'MASARIFE HANOUT', 'expense' => 'O']),
        ]]);
        Cache::flush();
        $this->actingAs($boss)->get('/dashboard?period=today')->assertOk()->assertSee('1 000 000,00')->assertSee('899 975,54');
    }

    public function test_the_calendar_gives_the_cash_of_each_day_only_with_the_permission(): void
    {
        [, $oran] = $this->oran();
        $boss = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'cash.view'])->create();

        $json = $this->actingAs($boss)->getJson('/dashboard/calendar?month=2026-11')->assertOk()->assertJson(['cash' => true]);
        $days = collect($json->json('days'))->keyBy('date');
        $this->assertEquals([0, 82740167.53], [$days['2026-11-04']['cash_in'], $days['2026-11-04']['cash_out']]);
        $this->assertEquals([502745, 800000], [$days['2026-11-05']['cash_in'], $days['2026-11-05']['cash_out']]);

        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale'])->create();
        $json = $this->actingAs($seller)->getJson('/dashboard/calendar?month=2026-11')->assertOk()->assertJson(['cash' => false]);
        $this->assertNull($json->json('days.4.cash_in'));
        $this->assertStringNotContainsString('82740167', $json->getContent());
    }

    public function test_only_with_the_cash_permission(): void
    {
        [, $oran] = $this->oran();
        $seller = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'prices.sale', 'prices.buy'])->create();
        $html = $this->actingAs($seller)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString(e(__('dashboard.cash.title')), $html);
        $this->assertStringNotContainsString('1 099 975', $html);

        // la caisse se voit sur "لوحة القيادة" : la cocher coche aussi "المبيعات"
        $this->assertSame(['sales.view', 'cash.view'], Permissions::normalize(['cash.view']));
        $this->assertNotContains('cash.view', Permissions::DEFAULTS);
    }

    public function test_a_user_of_a_never_sees_the_cash_of_b_and_an_older_agent_is_named(): void
    {
        [, $oran] = $this->oran();
        [$setifToken, $setif] = $this->register('Setif');
        $this->cash($setifToken, 'cash_in', ['2026-11-05' => [$this->line('2026-11-05', 1, '08:00:00', '7777.77', ['label' => 'Client: Setif'])]]);
        [, $blida] = $this->register('Blida', '1.0.7');

        $karim = User::factory()->inBranch($oran)->withPermissions(['sales.view', 'cash.view'])->create();
        $html = $this->actingAs($karim)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('1 099 975,54', $html);
        $this->assertStringNotContainsString('7 777,77', $html);
        $this->assertStringNotContainsString('1 107 753,31', $html);

        // le directeur : le total et chaque branche dans sa couleur ; Blida (agent 1.0.7) nommee, pas comptee
        $owner = User::factory()->owner()->withPermissions(['sales.view', 'cash.view'])->create();
        $owner->branches()->attach([$oran->id, $setif->id, $blida->id]);
        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();
        foreach (['1 107 753,31', '7 777,77', '1 099 975,54', '--bh: '.$setif->hue(),
            e(__('dashboard.cash.outdated', ['version' => '1.0.8', 'names' => 'Blida']))] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Oran d'abord : la plus grosse caisse
        $this->assertMatchesRegularExpression('/Oran.*Setif/s', substr($html, strpos($html, 'id="cash-title"')));

        // seulement Blida : la caisse attend son agent
        $this->actingAs($owner)->post('/branch-scope', ['branch_id' => $blida->id]);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('1 099 975');
    }
}
