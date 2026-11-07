<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ProductGroup;
use App\Support\Reconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function pay(Branch $branch, string $kind, string $partner, int $number, string $date, float $amount, string $type = 'Espéce', ?string $note = null): void
    {
        Payment::forceCreate([
            'branch_id' => $branch->id, 'partner_kind' => $kind, 'partner_code' => $partner, 'number' => $number, 'date' => $date,
            'type' => $type, 'amount' => $amount, 'note' => $note, 'gescomm_key' => $kind.$number, 'sync_group' => $date,
        ]);
    }
}
