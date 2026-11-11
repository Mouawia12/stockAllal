<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Exercise;
use App\Models\MergeDismissal;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ProductGroup;
use App\Support\Reconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "المطابقة" (App\Support\Reconciliation) : Blida vend a Oran. Chez Blida, Oran est le client ORAN ; chez Oran, Blida
 * est le fournisseur BLIDA. Les bons n'ont jamais le meme numero des deux cotes : ils sont apparies par leurs articles,
 * leur montant et leur date, et la somme des effets des problemes redonne l'ecart des soldes.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $blida;

    private Branch $oran;

    private Partner $customer;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blida = Branch::create(['name' => 'Blida', 'active' => true]);
        $this->oran = Branch::create(['name' => 'Oran', 'active' => true]);
        $this->customer = $this->partner($this->blida, 'customer', 'ORAN', 'Succursale Oran');
        $this->supplier = $this->partner($this->oran, 'supplier', 'BLIDA', 'Succursale Blida');
    }

    public function test_bons_are_paired_without_their_numbers_and_every_gap_is_explained(): void
    {
        // 1. le meme bon, saisi le lendemain chez Oran, avec un autre numero
        $this->bon($this->blida, 'sale', 'ORAN', 100, '2026-10-01', [['820AC', 10, 550], ['1230', 5, 140]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 7, '2026-10-02', [['820AC', 10, 550], ['1230', 5, 140]]);
        // 2. vendu par Blida, jamais saisi chez Oran
        $this->bon($this->blida, 'sale', 'ORAN', 101, '2026-10-05', [['820AC', 4, 550]]);
        // 3. un article oublie chez Oran
        $this->bon($this->blida, 'sale', 'ORAN', 102, '2026-10-08', [['X1', 2, 1000], ['X2', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 9, '2026-10-08', [['X1', 2, 1000]]);
        // 4. une quantite differente
        $this->bon($this->blida, 'sale', 'ORAN', 103, '2026-10-10', [['Y', 10, 50]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 10, '2026-10-10', [['Y', 8, 50]]);
        // 5. achete chez Oran, aucune vente chez Blida
        $this->bon($this->oran, 'purchase', 'BLIDA', 11, '2026-10-12', [['Z', 1, 900]]);
        // 6. un bon saisi en deux fois chez Oran
        $this->bon($this->blida, 'sale', 'ORAN', 104, '2026-10-15', [['W', 10, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 12, '2026-10-15', [['W', 6, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 13, '2026-10-16', [['W', 4, 100]]);
        // 7. deux codes fusionnes ("دمج الأصناف") sont le meme article
        ProductGroup::merge(['820AC', '820-AC'], null);
        $this->bon($this->blida, 'sale', 'ORAN', 105, '2026-10-18', [['820AC', 2, 550]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 14, '2026-10-18', [['820-AC', 2, 550]]);

        // versements : le meme, un oublie de chaque cote, un montant tape de travers, et les soldes initiaux
        $this->pay($this->blida, 'customer', 'ORAN', 1, '2026-10-20', 5000);
        $this->pay($this->oran, 'supplier', 'BLIDA', 1, '2026-10-21', 5000);
        $this->pay($this->blida, 'customer', 'ORAN', 2, '2026-10-25', 3000);
        $this->pay($this->oran, 'supplier', 'BLIDA', 2, '2026-10-28', 1500);
        $this->pay($this->blida, 'customer', 'ORAN', 3, '2026-10-30', 2000);
        $this->pay($this->oran, 'supplier', 'BLIDA', 3, '2026-10-30', 2200);
        $this->pay($this->blida, 'customer', 'ORAN', 4, '2026-01-01', -10000, 'Autre', "Solde Initial Client de l'exercice 2026");
        $this->pay($this->oran, 'supplier', 'BLIDA', 4, '2026-01-01', -9000, 'Autre', "Solde Initial Fournisseur de l'exercice 2026");

        $r = Reconciliation::run($this->customer, $this->supplier);

        // soldes : bons - versements, tous types ("Autre" negatif = solde initial du)
        $this->assertSame(13300.0, $r['a']['amount']);
        $this->assertSame(0.0, $r['a']['payments']);
        $this->assertSame(13300.0, $r['a']['balance']);
        $this->assertSame(11600.0, $r['b']['amount']);
        $this->assertSame(11900.0, $r['b']['balance']);
        $this->assertSame(1400.0, $r['gap']);
        $this->assertSame($r['gap'], $r['explained'], 'the problems explain the whole gap');

        $this->assertSame(2, $r['counts']['same']);
        $byType = collect($r['problems'])->groupBy('type');
        $this->assertSame([2200.0], $byType['bon_missing_b']->pluck('effect')->all());
        $this->assertSame(101, $byType['bon_missing_b'][0]['a'][0]['number']);
        $this->assertSame([-900.0], $byType['bon_missing_a']->pluck('effect')->all());
        $this->assertSame(11, $byType['bon_missing_a'][0]['b'][0]['number']);

        $amounts = $byType['bon_amount']->keyBy(fn ($p) => $p['a'][0]['number']);
        $this->assertSame(300.0, $amounts[102]['effect']);
        $this->assertSame(9, $amounts[102]['b'][0]['number']);
        $this->assertSame([['code' => 'X2', 'kind' => 'only_a', 'qty_a' => 3.0, 'total_a' => 300.0]], $amounts[102]['lines']);
        $this->assertSame(100.0, $amounts[103]['effect']);
        $this->assertSame('qty', $amounts[103]['lines'][0]['kind']);
        $this->assertSame([10.0, 8.0], [$amounts[103]['lines'][0]['qty_a'], $amounts[103]['lines'][0]['qty_b']]);

        $split = $byType['bon_split'][0];
        $this->assertSame(0.0, $split['effect']);
        $this->assertSame([104], array_column($split['a'], 'number'));
        $this->assertSame([12, 13], array_column($split['b'], 'number'));
        $this->assertArrayNotHasKey('bon_lines', $byType->all(), 'merged codes count as the same item');

        $this->assertSame([-3000.0], $byType['pay_missing_b']->pluck('effect')->all());
        $this->assertSame([1500.0], $byType['pay_missing_a']->pluck('effect')->all());
        $this->assertSame([200.0], $byType['pay_amount']->pluck('effect')->all());
        $this->assertSame([1000.0], $byType['opening']->pluck('effect')->all());
        $this->assertSame(1, $r['counts']['pay_same']);

        // le plus gros effet d'abord
        $this->assertSame('pay_missing_b', $r['problems'][0]['type']);
        $this->assertSame('BOULLON', $r['names']['820AC'] ?? null);

        // le rapport par groupes (ce qu'il faut faire), dans l'ordre de GROUPS ; leur somme est l'ecart
        $groups = Reconciliation::groups($r);
        $this->assertSame(['missing_b', 'missing_a', 'amount', 'payments', 'opening'], array_column($groups, 'key'));
        $this->assertSame([2200.0, -900.0, 400.0, -1300.0, 1000.0], array_column($groups, 'effect'));
        $this->assertSame([1, 1, 3, 3, 1], array_column($groups, 'count'));
        $this->assertSame($r['gap'], round(array_sum(array_column($groups, 'effect')), 2));
        // une cle de groupe = l'index du probleme dans le rapport : la fenetre rapide garde l'ordre de la page
        $amount = $groups[2]['problems'];
        $this->assertSame(array_map(fn ($i) => $r['problems'][$i], array_keys($amount)), array_values($amount));
    }

    public function test_matching_books_have_no_problem(): void
    {
        $this->bon($this->blida, 'sale', 'ORAN', 200, '2026-11-01', [['A1', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 50, '2026-11-01', [['A1', 3, 100]]);
        $this->pay($this->blida, 'customer', 'ORAN', 9, '2026-11-02', 300);
        $this->pay($this->oran, 'supplier', 'BLIDA', 9, '2026-11-03', 300);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame(0.0, $r['gap']);
        $this->assertSame([], $r['problems']);
        $this->assertSame(1, $r['counts']['same']);
        $this->assertSame(200, $r['same'][0]['a']['number']);
        $this->assertSame(50, $r['same'][0]['b']['number']);
    }

    public function test_bons_without_lines_pair_only_on_the_exact_amount_and_far_dates_never_pair(): void
    {
        $this->bon($this->blida, 'sale', 'ORAN', 300, '2026-09-01', [], 4500);
        $this->bon($this->oran, 'purchase', 'BLIDA', 60, '2026-09-03', [], 4500);
        $this->bon($this->blida, 'sale', 'ORAN', 301, '2026-09-10', [], 1000);
        $this->bon($this->oran, 'purchase', 'BLIDA', 61, '2026-09-10', [], 999);
        // meme montant, mais trois mois plus tard : deux bons differents
        $this->bon($this->blida, 'sale', 'ORAN', 302, '2026-06-01', [['Q', 1, 700]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 62, '2026-09-20', [['Q', 1, 700]]);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame(1, $r['counts']['same']);
        $this->assertSame(2, $r['counts']['bon_missing_b']);
        $this->assertSame(2, $r['counts']['bon_missing_a']);
        $this->assertSame($r['gap'], $r['explained']);
    }

    /**
     * Un code tout en chiffres devient un entier comme cle de tableau PHP ; sur MySQL, comparer code_key a 1640 trouvait
     * aussi "1640A", et la page tombait en erreur 500 (Parck Alg / stockArtisana, 07/11/2026). SQLite ne compare pas
     * ainsi : ce test garde au moins les designations justes pour "1640" et "1640A" ensemble.
     */
    public function test_numeric_item_codes_keep_their_own_designation(): void
    {
        $this->bon($this->blida, 'sale', 'ORAN', 400, '2026-08-01', [['1640', 2, 100], ['1640A', 1, 50], ['0820', 1, 10]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 70, '2026-08-01', [['1640', 2, 100]]);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame('ART 1640', $r['names']['1640']);
        $this->assertSame('ART 1640A', $r['names']['1640A']);
        $this->assertSame('ART 0820', $r['names']['0820']);
        $this->assertSame(['1640A', '0820'], array_column($r['problems'][0]['lines'], 'code'));
        $this->assertSame($r['gap'], $r['explained']);
    }

    public function test_items_with_two_codes_are_learned_from_the_code_the_name_and_identical_bons(): void
    {
        // la meme designation chez les deux, ecrite autrement
        $this->stock($this->blida, 'P100', 'ROBINET LAITON 1/2');
        $this->stock($this->oran, 'RB12', 'Robinet laiton 1/2');
        // 77-X chez Blida = 77X chez Oran (le code ecrit autrement) ; P100 = RB12 (la designation)
        $this->bon($this->blida, 'sale', 'ORAN', 500, '2026-07-01', [['77-X', 1, 300], ['P100', 2, 80]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 80, '2026-07-01', [['77X', 1, 300], ['RB12', 2, 80]]);
        // deux bons identiques a un code pres, meme montant : Q1 = QQ9
        $this->bon($this->blida, 'sale', 'ORAN', 501, '2026-07-05', [['P2', 2, 100], ['Q1', 1, 50]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 81, '2026-07-05', [['P2', 2, 100], ['QQ9', 1, 50]]);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame(2, $r['counts']['same']);
        $this->assertSame([], $r['problems']);
        $codes = collect($r['codes'])->keyBy('b');
        $this->assertSame(['a' => '77-X', 'how' => 'code'], collect($codes['77X'])->only(['a', 'how'])->all());
        $this->assertSame(['a' => 'P100', 'how' => 'name', 'name_a' => 'ROBINET LAITON 1/2', 'name_b' => 'Robinet laiton 1/2'],
            collect($codes['RB12'])->only(['a', 'how', 'name_a', 'name_b'])->all());
        $this->assertSame(['a' => 'Q1', 'how' => 'bons', 'bons' => 1], collect($codes['QQ9'])->only(['a', 'how', 'bons'])->all());
    }

    public function test_a_code_typed_wrong_in_one_bon_never_renames_a_code_both_sides_have(): void
    {
        // EN14 chez les deux ; un seul bon d'Oran a EN14 la ou Blida a EA14 (Parck Alg / Artisana, 07/11/2026)
        $this->bon($this->blida, 'sale', 'ORAN', 455, '2026-07-01', [['P2', 2, 100], ['EA14', 1, 50]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 202, '2026-07-01', [['P2', 2, 100], ['EN14', 1, 50]]);
        $this->bon($this->blida, 'sale', 'ORAN', 456, '2026-07-10', [['EN14', 3, 150]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 203, '2026-07-10', [['EN14', 3, 150]]);
        $this->bon($this->blida, 'sale', 'ORAN', 457, '2026-07-20', [['EN14', 2, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 204, '2026-07-20', [['EN14', 1, 50]]);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame([], $r['codes']);
        $this->assertSame(1, $r['counts']['same']);
        $byNumber = collect($r['problems'])->keyBy(fn (array $p) => $p['a'][0]['number']);
        $this->assertSame('bon_lines', $byNumber[455]['type']);
        $this->assertSame(['only_a', 'only_b'], array_column($byNumber[455]['lines'], 'kind'));
        // 457 / 204 : la quantite d'EN14, pas « EN14 chez Blida seulement »
        $this->assertSame('bon_amount', $byNumber[457]['type']);
        $this->assertSame([['code' => 'EN14', 'kind' => 'qty']],
            array_map(fn (array $l) => ['code' => $l['code'], 'kind' => $l['kind']], $byNumber[457]['lines']));
    }

    public function test_a_pair_marked_not_the_same_item_is_never_learned(): void
    {
        $this->stock($this->blida, 'P100', 'ROBINET LAITON 1/2');
        $this->stock($this->oran, 'RB12', 'Robinet laiton 1/2');
        MergeDismissal::remember(['P100', 'RB12'], null);
        $this->bon($this->blida, 'sale', 'ORAN', 510, '2026-07-01', [['P100', 2, 80]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 85, '2026-07-01', [['RB12', 2, 80]]);

        $r = Reconciliation::run($this->customer, $this->supplier);

        $this->assertSame([], $r['codes']);
        $this->assertSame('bon_lines', $r['problems'][0]['type']);
    }

    public function test_the_deep_checks_find_split_late_twice_and_other_partner_bons(): void
    {
        $other = $this->partner($this->oran, 'supplier', 'AUTRE', 'Fournisseur Autre');
        $this->partner($this->blida, 'customer', 'C9', 'Benali');
        // un bon de 900 saisi en trois bons de 300
        $this->bon($this->blida, 'sale', 'ORAN', 600, '2026-05-10', [['S', 9, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 90, '2026-05-10', [['S', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 91, '2026-05-11', [['S', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 92, '2026-05-12', [['S', 3, 100]]);
        // le meme bon, saisi 100 jours plus tard
        $this->bon($this->blida, 'sale', 'ORAN', 601, '2026-01-10', [['L1', 1, 100], ['L2', 2, 50]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 93, '2026-04-20', [['L1', 1, 100], ['L2', 2, 50]]);
        // saisi deux fois chez Blida
        $this->bon($this->blida, 'sale', 'ORAN', 602, '2026-06-01', [['D', 1, 500]]);
        $this->bon($this->blida, 'sale', 'ORAN', 603, '2026-06-02', [['D', 1, 500]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 94, '2026-06-01', [['D', 1, 500]]);
        // une vente de Blida achetee chez Oran sous un autre fournisseur, un achat d'Oran vendu par Blida a un autre client
        $this->bon($this->blida, 'sale', 'ORAN', 604, '2026-08-01', [['E1', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'AUTRE', 95, '2026-08-02', [['E1', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 96, '2026-08-10', [['F1', 1, 700]]);
        $this->bon($this->blida, 'sale', 'C9', 605, '2026-08-10', [['F1', 1, 700]]);
        // un versement recu par Blida, paye par Oran a un autre fournisseur le lendemain
        $this->pay($this->blida, 'customer', 'ORAN', 30, '2026-08-15', 4000);
        $this->pay($this->oran, 'supplier', 'AUTRE', 31, '2026-08-16', 4000);

        $r = Reconciliation::run($this->customer, $this->supplier);
        $byType = collect($r['problems'])->keyBy('type');

        $this->assertSame([[600], [90, 91, 92]], [array_column($byType['bon_split']['a'], 'number'), array_column($byType['bon_split']['b'], 'number')]);
        $this->assertSame(1, $r['counts']['late']);
        $this->assertTrue(collect($r['same'])->firstWhere('a.number', 601)['late']);
        $this->assertSame(603, $byType['bon_duplicate_a']['a'][0]['number']);
        $this->assertSame(602, $byType['bon_duplicate_a']['twin']['number']);
        $this->assertSame(['number' => 95, 'partner_code' => 'AUTRE', 'partner' => 'Fournisseur Autre', 'sure' => true],
            collect($byType['bon_elsewhere_b']['other'])->only(['number', 'partner_code', 'partner', 'sure'])->all());
        $this->assertSame(604, $byType['bon_elsewhere_b']['a'][0]['number']);
        $this->assertSame(['number' => 605, 'partner_code' => 'C9'], collect($byType['bon_elsewhere_a']['other'])->only(['number', 'partner_code'])->all());
        $this->assertSame(['partner_code' => 'AUTRE', 'sure' => false], collect($byType['pay_elsewhere_b']['other'])->only(['partner_code', 'sure'])->all());
        $this->assertSame(-4000.0, $byType['pay_elsewhere_b']['effect']);
        $this->assertSame($r['gap'], $r['explained']);
        $this->assertSame(round(array_sum(array_column($r['timeline'], 'effect')), 2), $r['gap']);
        $this->assertSame($r['gap'], end($r['timeline'])['running']);
        $this->assertNotSame($other->id, null);
    }

    public function test_the_steps_run_one_by_one_from_a_saved_state_give_the_same_result(): void
    {
        $this->bon($this->blida, 'sale', 'ORAN', 700, '2026-03-01', [['A1', 3, 100]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 99, '2026-03-02', [['A1', 2, 100]]);

        $run = Reconciliation::start($this->customer, $this->supplier);
        foreach (Reconciliation::STEPS as $step) {
            // chaque etape repart d'un tableau, comme entre deux requetes (le cache ne relit aucun objet)
            $run = Reconciliation::resume(unserialize(serialize($run->state()), ['allowed_classes' => false]));
            $this->assertSame($step, $run->next());
            $run->step($step);
        }
        $this->assertNull($run->next());
        $byStep = $run->report();
        $direct = Reconciliation::run($this->customer, $this->supplier);
        unset($byStep['analyzed_at'], $direct['analyzed_at']);
        $this->assertSame($direct, $byStep);
        $this->assertSame(['sales' => 1, 'sale_lines' => 1, 'purchases' => 1, 'purchase_lines' => 1, 'payments_a' => 0, 'payments_b' => 0], $byStep['steps']['load']);
        $this->assertNull(Reconciliation::resume('garbage'));
    }

    public function test_only_a_customer_and_a_supplier_of_two_branches_the_account_sees(): void
    {
        $this->assertTrue(Reconciliation::allowed($this->customer, $this->supplier, null));
        $this->assertTrue(Reconciliation::allowed($this->customer, $this->supplier, [$this->blida->id, $this->oran->id]));
        $this->assertFalse(Reconciliation::allowed($this->customer, $this->supplier, [$this->blida->id]));
        $this->assertFalse(Reconciliation::allowed($this->supplier, $this->customer, null));
        $sameBranch = $this->partner($this->blida, 'supplier', 'ORAN', 'Oran');
        $this->assertFalse(Reconciliation::allowed($this->customer, $sameBranch, null));
        $this->oran->update(['active' => false]);
        $this->assertFalse(Reconciliation::allowed($this->customer, $this->supplier, null));
    }

    public function test_two_branches_closing_on_different_days_are_compared_over_the_same_period(): void
    {
        // Blida cloture le 01/06 (son exercice 2026A reporte 1000), Oran le 01/07 (son 2026B reporte 2700)
        $this->bon($this->blida, 'sale', 'ORAN', 10, '2026-05-10', [['P1', 1, 1000]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 50, '2026-05-10', [['P1', 1, 1000]]);
        $this->bon($this->blida, 'sale', 'ORAN', 11, '2026-06-15', [['P2', 1, 2000]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 51, '2026-06-15', [['P2', 1, 2000]]);
        $this->pay($this->blida, 'customer', 'ORAN', 1, '2026-06-20', 300);
        $this->pay($this->oran, 'supplier', 'BLIDA', 1, '2026-06-20', 300);
        // jamais saisi chez Oran
        $this->bon($this->blida, 'sale', 'ORAN', 12, '2026-07-10', [['P3', 1, 500]]);
        $this->closeAt($this->blida, 'customer', 'ORAN', '2026A', '2026-06-01', 1000);
        $this->closeAt($this->oran, 'supplier', 'BLIDA', '2026B', '2026-07-01', 2700);

        // par defaut : depuis le 01/07, debut du plus recent des deux exercices en cours ; le meme solde ce jour-la
        $r = Reconciliation::run($this->customer, $this->supplier);
        $this->assertSame(['from' => '2026-07-01', 'to' => null, 'auto' => true], $r['period']);
        $this->assertSame([2700.0, 2700.0, 0.0], [$r['a']['start'], $r['b']['start'], $r['gap_start']]);
        $this->assertSame(['bon_missing_b'], array_column($r['problems'], 'type'), 'not the bons of June, not the openings');
        $this->assertSame([500.0, 500.0], [$r['gap'], $r['explained']]);

        // depuis le 01/05 : les deux exercices de chacun ; les "Solde Initial" qui reportent un exercice ne comptent pas
        $r = Reconciliation::run($this->customer, $this->supplier, '2026-05-01');
        $this->assertSame([0.0, 0.0], [$r['a']['start'], $r['b']['start']]);
        $this->assertSame(['bon_missing_b'], array_column($r['problems'], 'type'));
        $this->assertSame([2, 1], [$r['counts']['same'], $r['counts']['pay_same']]);
        $this->assertSame([500.0, 500.0], [$r['gap'], $r['explained']]);
    }

    public function test_a_bon_entered_before_the_period_on_one_side_and_after_on_the_other_is_at_the_boundary(): void
    {
        $this->bon($this->blida, 'sale', 'ORAN', 11, '2026-06-14', [['P2', 1, 2000]]);
        $this->bon($this->oran, 'purchase', 'BLIDA', 51, '2026-06-16', [['P2', 1, 2000]]);

        $r = Reconciliation::run($this->customer, $this->supplier, '2026-06-15');

        // dans le solde du debut chez Blida, dans la periode chez Oran : le meme bon, pas un bon manquant
        $this->assertSame([2000.0, 0.0, 2000.0], [$r['a']['start'], $r['b']['start'], $r['gap_start']]);
        $this->assertSame([['bon_boundary', -2000.0, 'a']], array_map(fn ($p) => [$p['type'], $p['effect'], $p['outside']], $r['problems']));
        $this->assertSame([0.0, 0.0], [$r['gap'], $r['explained']]);
    }

    /** La cloture de Gescomm : avant ce jour, un exercice clos ; un "Solde Initial" du jour reporte le solde. */
    private function closeAt(Branch $branch, string $kind, string $partner, string $name, string $day, float $carry): void
    {
        $closed = Exercise::create(['branch_id' => $branch->id, 'current' => false, 'name' => $name, 'first_day' => '2026-01-01',
            'last_day' => date('Y-m-d', strtotime("$day -1 day")), 'closed_at' => now()]);
        foreach (Exercise::TABLES as $table) {
            DB::table($table)->where('branch_id', $branch->id)->where('exercise_id', 0)->where('date', '<', $day)->update(['exercise_id' => $closed->id]);
        }
        Exercise::create(['branch_id' => $branch->id, 'current' => true, 'opened_on' => $day, 'opening_label' => $name, 'first_day' => $day]);
        $this->pay($branch, $kind, $partner, 900, $day, -$carry, 'Autre', 'Solde Initial '.($kind === 'customer' ? 'Client' : 'Fournisseur')." de l'exercice $name");
    }

    private function partner(Branch $branch, string $kind, string $code, string $name): Partner
    {
        return Partner::forceCreate(['branch_id' => $branch->id, 'kind' => $kind, 'code' => $code, 'name' => $name, 'gescomm_key' => $code]);
    }

    /** @param  list<array{0: string, 1: float|int, 2: float|int}>  $lines  code, quantite, prix */
    private function bon(Branch $branch, string $kind, string $partner, int $number, string $date, array $lines, ?float $amount = null): Document
    {
        $doc = Document::forceCreate([
            'branch_id' => $branch->id, 'kind' => $kind, 'number' => $number, 'date' => $date, 'partner_code' => $partner,
            'amount' => $amount ?? array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines)), 'gescomm_key' => (string) $number, 'sync_group' => $date,
        ]);
        foreach ($lines as $pos => [$code, $qty, $price]) {
            DocumentLine::forceCreate([
                'document_id' => $doc->id, 'branch_id' => $branch->id, 'kind' => $kind, 'date' => $date, 'position' => $pos + 1,
                'code_raw' => $code, 'code_key' => BranchStock::keyFor($code), 'qty' => $qty, 'price' => $price, 'total' => $qty * $price,
            ]);
            BranchStock::firstOrCreate(['branch_id' => $branch->id, 'code_key' => BranchStock::keyFor($code)],
                ['code_raw' => $code, 'designation' => $code === '820AC' ? 'BOULLON' : 'ART '.$code, 'stock' => 1, 'seen_at' => now(), 'updated_at' => now()]);
        }

        return $doc;
    }

    private function stock(Branch $branch, string $code, string $designation): void
    {
        BranchStock::forceCreate(['branch_id' => $branch->id, 'code_key' => BranchStock::keyFor($code), 'code_raw' => $code,
            'designation' => $designation, 'stock' => 1, 'seen_at' => now(), 'updated_at' => now()]);
    }

    private function pay(Branch $branch, string $kind, string $partner, int $number, string $date, float $amount, string $type = 'Espéce', ?string $note = null): void
    {
        Payment::forceCreate([
            'branch_id' => $branch->id, 'partner_kind' => $kind, 'partner_code' => $partner, 'number' => $number, 'date' => $date,
            'type' => $type, 'amount' => $amount, 'note' => $note, 'gescomm_key' => $kind.$number, 'sync_group' => $date,
        ]);
    }
}
