<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\MergeDismissal;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ProductGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * "المطابقة" entre deux branches qui commercent : A vend a B. Chez A, B est un client (GC002) ; chez B, A est un
 * fournisseur (GC004). Chaque bon de vente de A a ce client devrait exister chez B comme bon d'achat de ce fournisseur
 * (autre numero, meme jour ou quelques jours apres, memes articles, quantites, prix et montant), et chaque versement
 * de B devrait exister chez A. Les deux soldes seraient alors egaux.
 *
 * Sans IA, en etapes (STEPS) : la page les lance une a une et montre ce que chacune trouve (notes) ; run() les fait
 * toutes d'un coup (chat, tests).
 *   load     : les bons et versements des deux cotes ; les codes fusionnes ("دمج الأصناف") comptent pour un article.
 *   codes    : le meme article sous deux codes (ecrit pareil 820-AC / 820AC, meme designation, ou deduit de bons
 *              identiques a un code pres), jamais une paire dite "ليست نفس الصنف" ; puis les paires de bons par
 *              articles, montant et date (31 jours), jamais par le numero.
 *   deep     : un bon saisi en deux ou trois bons de l'autre cote ; un bon identique saisi jusqu'a 4 mois plus tard ;
 *              un bon saisi deux fois du meme cote.
 *   others   : un bon sans pendant chez ce partenaire, retrouve de l'autre cote sous un AUTRE client ou fournisseur.
 *   payments : les versements (soldes initiaux, meme montant, faute de frappe), ceux retrouves sous un autre
 *              partenaire (peut-etre), puis le rapport et le mois ou l'ecart est apparu.
 * Chaque probleme a son effet sur (solde A - solde B) : la somme des effets redonne exactement l'ecart. Solde =
 * bons - versements, tous types, "Autre" avec son signe ; le solde initial est un versement "Autre" negatif
 * "Solde Initial ..." (agent/tools/check-balances.ps1, 04/11/2026). L'IA (ReconciliationChat) explique ce rapport.
 */
final class Reconciliation
{
    public const STEPS = ['load', 'codes', 'deep', 'others', 'payments'];

    /** Ecart maximal entre la date d'un bon chez A et chez B. */
    public const WINDOW_DAYS = 31;

    /** Un bon identique (au moins deux articles) saisi bien plus tard : jusqu'a 4 mois. */
    public const LATE_DAYS = 120;

    /** Un bon ou un versement par categorie dans le rapport : au-dela, "et N autres". */
    public const MAX_LISTED = 400;

    private const CENT = 0.005;

    /** Score minimal d'une paire de bons (0 a 1). */
    private const MIN_SCORE = 0.45;

    private int $customerId;

    private int $supplierId;

    /** @var list<array<string, mixed>> bons de vente de A, puis d'achat de B (lignes par cle d'article) */
    private array $a = [];

    private array $b = [];

    private array $payA = [];

    private array $payB = [];

    /** @var array<string, string> code_key => cle commune (codes fusionnes) */
    private array $canon = [];

    /** @var array<string, string> cle chez B => cle chez A : le meme article sous deux codes */
    private array $map = [];

    /** @var list<array{a: string, b: string, how: string}> */
    private array $learned = [];

    /** @var array<string, int> cle chez B => bons ou elle a ete remplacee */
    private array $usage = [];

    private array $pairs = [];

    private array $restA = [];

    private array $restB = [];

    private array $splits = [];

    /** @var array<string, true> "i:j" : paire trouvee au-dela de 31 jours */
    private array $late = [];

    private array $dups = ['a' => [], 'b' => []];

    private array $elsewhere = ['a' => [], 'b' => []];

    private array $done = [];

    private ?array $report = null;

    /** @var array<string, array<string, int>> ce que chaque etape a trouve */
    public array $notes = [];

    /** @return array<string, mixed> */
    public static function run(Partner $customer, Partner $supplier): array
    {
        $r = self::start($customer, $supplier);
        while (($step = $r->next()) !== null) {
            $r->step($step);
        }

        return $r->report();
    }

    public static function start(Partner $customer, Partner $supplier): self
    {
        $r = new self;
        $r->customerId = $customer->id;
        $r->supplierId = $supplier->id;

        return $r;
    }

    /**
     * L'etat d'une analyse en cours, en tableau : le cache ne relit aucun objet (cache.serializable_classes = false,
     * contre les "gadget chains" si APP_KEY fuit).
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return get_object_vars($this);
    }

    public static function resume(mixed $state): ?self
    {
        if (! is_array($state) || ! isset($state['customerId'], $state['supplierId'], $state['done'])) {
            return null;
        }
        $r = new self;
        foreach ($state as $name => $value) {
            if (property_exists($r, $name)) {
                $r->{$name} = $value;
            }
        }

        return $r;
    }

    /** La prochaine etape, null quand l'analyse est finie. */
    public function next(): ?string
    {
        foreach (self::STEPS as $step) {
            if (! in_array($step, $this->done, true)) {
                return $step;
            }
        }

        return null;
    }

    /** @return array<string, int> ce que l'etape a trouve */
    public function step(string $step): array
    {
        if ($step !== $this->next()) {
            throw new LogicException("step $step out of order");
        }
        $customer = Partner::with('branch')->findOrFail($this->customerId);
        $supplier = Partner::with('branch')->findOrFail($this->supplierId);
        match ($step) {
            'load' => $this->load($customer, $supplier),
            'codes' => $this->codes($customer, $supplier),
            'deep' => $this->deep(),
            'others' => $this->others($customer, $supplier),
            'payments' => $this->finish($customer, $supplier),
        };
        $this->done[] = $step;

        return $this->notes[$step] ?? [];
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->report ?? throw new LogicException('analysis not finished');
    }

    // ---------- etapes ----------

    private function load(Partner $customer, Partner $supplier): void
    {
        $this->a = self::bons($customer, 'sale');
        $this->b = self::bons($supplier, 'purchase');
        $this->canon = $this->canonicalKeys(array_merge($this->a, $this->b));
        $this->a = array_map(fn (array $bon) => self::rekey($bon, $this->canon), $this->a);
        $this->b = array_map(fn (array $bon) => self::rekey($bon, $this->canon), $this->b);
        $this->payA = self::payments($customer);
        $this->payB = self::payments($supplier);
        $this->notes['load'] = [
            'sales' => count($this->a), 'sale_lines' => array_sum(array_column($this->a, 'n_lines')),
            'purchases' => count($this->b), 'purchase_lines' => array_sum(array_column($this->b, 'n_lines')),
            'payments_a' => count($this->payA), 'payments_b' => count($this->payB),
        ];
    }

    private function codes(Partner $customer, Partner $supplier): void
    {
        $this->learnFromNames($customer->branch_id, $supplier->branch_id);
        [$this->pairs, $this->restA, $this->restB] = self::matchBons($this->a, $this->b);

        // des bons au meme montant, identiques a un article pres : cet article a deux codes
        $votes = [];
        foreach ($this->pairs as [$i, $j]) {
            $x = $this->a[$i];
            $y = $this->b[$j];
            if (abs($x['amount'] - $y['amount']) >= self::CENT) {
                continue;
            }
            $onlyA = array_values(array_diff(array_map('strval', array_keys($x['lines'])), array_map('strval', array_keys($y['lines']))));
            $onlyB = array_values(array_diff(array_map('strval', array_keys($y['lines'])), array_map('strval', array_keys($x['lines']))));
            if (count($onlyA) === 1 && count($onlyB) === 1) {
                $la = $x['lines'][$onlyA[0]];
                $lb = $y['lines'][$onlyB[0]];
                if (abs($la['qty'] - $lb['qty']) < self::CENT && abs($la['total'] - $lb['total']) < self::CENT) {
                    $votes[$onlyB[0]][$onlyA[0]] = true;
                }
            }
        }
        $found = [];
        foreach ($votes as $keyB => $targets) {
            if (count($targets) === 1) {
                $found[$keyB] = (string) array_key_first($targets);
            }
        }
        if ($this->accept($found, 'bons') > 0) {
            [$this->pairs, $this->restA, $this->restB] = self::matchBons($this->a, $this->b);
        }
        $this->notes['codes'] = ['pairs' => count($this->pairs), 'codes' => count($this->learned)];
    }

    private function deep(): void
    {
        [$this->splits, $this->restA, $this->restB] = self::splits($this->a, $this->b, $this->pairs, $this->restA, $this->restB);

        // un bon identique (au moins deux articles, meme montant) bien plus tard : saisi en retard
        $candidates = [];
        foreach ($this->restA as $i) {
            foreach ($this->restB as $j) {
                $days = abs($this->b[$j]['day'] - $this->a[$i]['day']);
                if ($days <= self::LATE_DAYS && count($this->a[$i]['lines']) >= 2
                    && abs($this->a[$i]['amount'] - $this->b[$j]['amount']) < self::CENT
                    && self::lineSimilarity($this->a[$i], $this->b[$j]) === 1.0) {
                    $candidates[] = [$days, $i, $j];
                }
            }
        }
        sort($candidates);
        $usedA = $usedB = [];
        foreach ($candidates as [, $i, $j]) {
            if (! isset($usedA[$i]) && ! isset($usedB[$j])) {
                $usedA[$i] = $usedB[$j] = true;
                $this->pairs[] = [$i, $j];
                $this->late["$i:$j"] = true;
            }
        }
        $this->restA = array_values(array_filter($this->restA, fn ($i) => ! isset($usedA[$i])));
        $this->restB = array_values(array_filter($this->restB, fn ($j) => ! isset($usedB[$j])));

        $this->dups['a'] = self::duplicates($this->a, $this->restA);
        $this->dups['b'] = self::duplicates($this->b, $this->restB);
        $this->notes['deep'] = ['splits' => count($this->splits), 'late' => count($this->late), 'dups' => count($this->dups['a']) + count($this->dups['b'])];
    }

    private function others(Partner $customer, Partner $supplier): void
    {
        // une vente de A sans pendant : chez B sous un autre fournisseur ? un achat de B : chez A sous un autre client ?
        $this->elsewhere['a'] = $this->elsewhere('a', array_diff($this->restA, array_keys($this->dups['a'])), $supplier->branch_id, 'purchase', 'supplier', $supplier->code);
        $this->elsewhere['b'] = $this->elsewhere('b', array_diff($this->restB, array_keys($this->dups['b'])), $customer->branch_id, 'sale', 'customer', $customer->code);
        $this->notes['others'] = ['found' => count($this->elsewhere['a']) + count($this->elsewhere['b'])];
    }

    private function finish(Partner $customer, Partner $supplier): void
    {
        $problems = $same = [];
        foreach ($this->pairs as [$i, $j]) {
            $x = $this->a[$i];
            $y = $this->b[$j];
            $diffs = self::lineDiffs($x, $y);
            $delta = round($x['amount'] - $y['amount'], 2);
            $days = $y['day'] - $x['day'];
            $late = isset($this->late["$i:$j"]);
            if (abs($delta) < self::CENT && $diffs === []) {
                $same[] = ['a' => self::ref($x), 'b' => self::ref($y), 'amount' => $x['amount'], 'days' => $days, 'late' => $late];

                continue;
            }
            $problems[] = ['type' => abs($delta) < self::CENT ? 'bon_lines' : 'bon_amount', 'effect' => $delta, 'date' => $x['date'],
                'days' => $days, 'late' => $late, 'a' => [self::bonOut($x)], 'b' => [self::bonOut($y)], 'lines' => $diffs];
        }
        foreach ($this->splits as [$is, $js]) {
            $sumA = array_sum(array_map(fn ($i) => $this->a[$i]['amount'], $is));
            $sumB = array_sum(array_map(fn ($j) => $this->b[$j]['amount'], $js));
            $problems[] = ['type' => 'bon_split', 'effect' => round($sumA - $sumB, 2), 'date' => $this->a[$is[0]]['date'],
                'a' => array_map(fn ($i) => self::bonOut($this->a[$i]), $is), 'b' => array_map(fn ($j) => self::bonOut($this->b[$j]), $js)];
        }
        foreach ($this->restA as $i) {
            $x = $this->a[$i];
            $p = ['effect' => round($x['amount'], 2), 'date' => $x['date'], 'a' => [self::bonOut($x)], 'b' => []];
            if (isset($this->dups['a'][$i])) {
                $p = ['type' => 'bon_duplicate_a', 'twin' => self::ref($this->a[$this->dups['a'][$i]])] + $p;
            } elseif (isset($this->elsewhere['a'][$i])) {
                $p = ['type' => 'bon_elsewhere_b', 'other' => $this->elsewhere['a'][$i]] + $p;
            } else {
                $p = ['type' => 'bon_missing_b'] + $p;
            }
            $problems[] = $p;
        }
        foreach ($this->restB as $j) {
            $y = $this->b[$j];
            $p = ['effect' => round(-$y['amount'], 2), 'date' => $y['date'], 'a' => [], 'b' => [self::bonOut($y)]];
            if (isset($this->dups['b'][$j])) {
                $p = ['type' => 'bon_duplicate_b', 'twin' => self::ref($this->b[$this->dups['b'][$j]])] + $p;
            } elseif (isset($this->elsewhere['b'][$j])) {
                $p = ['type' => 'bon_elsewhere_a', 'other' => $this->elsewhere['b'][$j]] + $p;
            } else {
                $p = ['type' => 'bon_missing_a'] + $p;
            }
            $problems[] = $p;
        }

        [$payPairs, $restPA, $restPB] = self::matchPayments($this->payA, $this->payB);
        $paySame = [];
        foreach ($payPairs as [$i, $j]) {
            $x = $this->payA[$i];
            $y = $this->payB[$j];
            $delta = round($x['amount'] - $y['amount'], 2);
            if (abs($delta) < self::CENT) {
                $paySame[] = ['a' => self::payOut($x), 'b' => self::payOut($y), 'days' => $y['day'] - $x['day']];

                continue;
            }
            // un versement de plus chez A baisse le solde de A : effet = -(A - B)
            $problems[] = ['type' => $x['opening'] ? 'opening' : 'pay_amount', 'effect' => -$delta, 'date' => $x['date'],
                'days' => $y['day'] - $x['day'], 'a' => [self::payOut($x)], 'b' => [self::payOut($y)]];
        }
        $otherA = $this->payElsewhere($this->payA, $restPA, $supplier->branch_id, 'supplier', $supplier->code);
        $otherB = $this->payElsewhere($this->payB, $restPB, $customer->branch_id, 'customer', $customer->code);
        foreach ($restPA as $i) {
            $x = $this->payA[$i];
            $type = $x['opening'] ? 'opening' : (isset($otherA[$i]) ? 'pay_elsewhere_b' : 'pay_missing_b');
            $problems[] = ['type' => $type, 'effect' => round(-$x['amount'], 2), 'date' => $x['date'], 'a' => [self::payOut($x)], 'b' => []]
                + (isset($otherA[$i]) ? ['other' => $otherA[$i]] : []);
        }
        foreach ($restPB as $j) {
            $y = $this->payB[$j];
            $type = $y['opening'] ? 'opening' : (isset($otherB[$j]) ? 'pay_elsewhere_a' : 'pay_missing_a');
            $problems[] = ['type' => $type, 'effect' => round($y['amount'], 2), 'date' => $y['date'], 'a' => [], 'b' => [self::payOut($y)]]
                + (isset($otherB[$j]) ? ['other' => $otherB[$j]] : []);
        }

        // le plus gros effet d'abord ; a effet egal (0 : articles, bons regroupes), le plus recent
        usort($problems, fn (array $x, array $y) => [abs($y['effect']), $y['date'] ?? ''] <=> [abs($x['effect']), $x['date'] ?? '']);
        usort($same, fn ($x, $y) => [$x['a']['date'], $x['a']['number']] <=> [$y['a']['date'], $y['a']['number']]);
        usort($paySame, fn ($x, $y) => $x['a']['date'] <=> $y['a']['date']);

        $sideA = self::side($customer, $this->a, $this->payA);
        $sideB = self::side($supplier, $this->b, $this->payB);
        $count = fn (string $type) => count(array_filter($problems, fn ($p) => $p['type'] === $type));
        $types = ['bon_amount', 'bon_lines', 'bon_split', 'bon_missing_b', 'bon_missing_a', 'bon_elsewhere_b', 'bon_elsewhere_a',
            'bon_duplicate_a', 'bon_duplicate_b', 'pay_amount', 'pay_missing_b', 'pay_missing_a', 'pay_elsewhere_b', 'pay_elsewhere_a', 'opening'];

        $this->report = [
            'a' => $sideA,
            'b' => $sideB,
            'gap' => round($sideA['balance'] - $sideB['balance'], 2),
            'explained' => round(array_sum(array_column($problems, 'effect')), 2),
            'counts' => ['same' => count($same), 'late' => count($this->late), 'codes' => count($this->learned), 'pay_same' => count($paySame)]
                + array_combine($types, array_map($count, $types)),
            'problems' => array_slice($problems, 0, self::MAX_LISTED),
            'more_problems' => max(0, count($problems) - self::MAX_LISTED),
            // les plus recents d'abord
            'same' => array_slice(array_reverse($same), 0, self::MAX_LISTED),
            'pay_same' => array_slice(array_reverse($paySame), 0, self::MAX_LISTED),
            'codes' => $this->codesOut($customer->branch_id, $supplier->branch_id),
            'timeline' => self::timeline($problems),
            'names' => self::names(array_merge($this->a, $this->b), [$customer->branch_id, $supplier->branch_id]),
            'steps' => $this->notes,
            'analyzed_at' => now()->toIso8601String(),
        ];
        $this->notes['payments'] = ['same' => count($paySame), 'problems' => count($problems) - count(array_filter($problems, fn ($p) => str_starts_with($p['type'], 'bon_')))];
        $this->report['steps'] = $this->notes;
    }

    // ---------- bons ----------

    /**
     * Les bons d'un partenaire, du plus ancien au plus recent, avec leurs lignes par code.
     *
     * @return list<array<string, mixed>>
     */
    private static function bons(Partner $partner, string $kind): array
    {
        $docs = Document::where('branch_id', $partner->branch_id)->where('kind', $kind)->where('partner_code', $partner->code)
            ->orderBy('date')->orderBy('time')->orderBy('number')->orderBy('id')
            ->get(['id', 'number', 'date', 'amount']);

        return self::withLines($docs);
    }

    /**
     * En-tetes de bons + leurs lignes (lues sans modeles : moins de memoire), par code_key.
     *
     * @return list<array<string, mixed>>
     */
    private static function withLines(Collection $docs): array
    {
        $lines = [];
        foreach ($docs->pluck('id')->chunk(500) as $ids) {
            DocumentLine::query()->toBase()->whereIn('document_id', $ids->all())
                ->orderBy('document_id')->orderBy('position')
                ->get(['document_id', 'code_raw', 'code_key', 'qty', 'total'])
                ->each(function ($l) use (&$lines) {
                    $key = (string) $l->code_key;
                    $lines[$l->document_id][$key] ??= ['code' => trim((string) $l->code_raw), 'qty' => 0.0, 'total' => 0.0];
                    $lines[$l->document_id][$key]['qty'] += (float) $l->qty;
                    $lines[$l->document_id][$key]['total'] += (float) $l->total;
                    $lines[$l->document_id]['#n'] = ($lines[$l->document_id]['#n'] ?? 0) + 1;
                });
        }

        return $docs->map(function (Document $d) use (&$lines) {
            $mine = $lines[$d->id] ?? [];
            $n = $mine['#n'] ?? 0;
            unset($mine['#n']);

            return [
                'id' => $d->id,
                'number' => $d->number,
                'date' => $d->date?->format('Y-m-d'),
                'day' => $d->date ? intdiv($d->date->getTimestamp(), 86400) : 0,
                'amount' => (float) $d->amount,
                'partner' => isset($d->partner_code) ? (string) $d->partner_code : null,
                'n_lines' => $n,
                'lines' => $mine,
            ];
        })->values()->all();
    }

    /**
     * Les codes fusionnes ("دمج الأصناف") comptent pour un seul article : 820AC chez A = 820-AC chez B.
     *
     * @return array<string, string> code_key => cle commune
     */
    private function canonicalKeys(array $bons): array
    {
        $keys = [];
        foreach ($bons as $bon) {
            foreach (array_keys($bon['lines']) as $k) {
                $k = (string) $k;
                if (! isset($this->canon[$k])) {
                    $keys[$k] = $k;
                }
            }
        }
        $found = $keys === [] ? [] : array_map(fn (array $members) => (string) $members[0], ProductGroup::expand(array_values($keys)));

        return $this->canon + $found;
    }

    /** Les lignes d'un bon sous d'autres cles ($map : ancienne => nouvelle), regroupees. */
    private static function rekey(array $bon, array $map, ?array &$usage = null): array
    {
        $lines = [];
        foreach ($bon['lines'] as $key => $line) {
            $key = (string) $key;
            $c = $map[$key] ?? $key;
            if ($usage !== null && isset($map[$key])) {
                $usage[$key] = ($usage[$key] ?? 0) + 1;
            }
            if (isset($lines[$c])) {
                $lines[$c]['qty'] += $line['qty'];
                $lines[$c]['total'] += $line['total'];
            } else {
                $lines[$c] = $line;
            }
        }
        $bon['lines'] = $lines;

        return $bon;
    }

    /**
     * Un code d'un seul cote, et un code de l'autre cote ecrit pareil (820-AC / 820AC) ou de meme designation :
     * le meme article, si personne n'a dit "ليست نفس الصنف" et s'il n'y a qu'un candidat.
     */
    private function learnFromNames(int $branchA, int $branchB): void
    {
        $keysA = $keysB = [];
        foreach ($this->a as $bon) {
            foreach (array_keys($bon['lines']) as $k) {
                $keysA[(string) $k] = true;
            }
        }
        foreach ($this->b as $bon) {
            foreach (array_keys($bon['lines']) as $k) {
                $keysB[(string) $k] = true;
            }
        }
        $onlyA = array_map('strval', array_keys(array_diff_key($keysA, $keysB)));
        $onlyB = array_map('strval', array_keys(array_diff_key($keysB, $keysA)));
        if ($onlyA === [] || $onlyB === []) {
            return;
        }
        $namesA = self::designations($branchA, $onlyA);
        $namesB = self::designations($branchB, $onlyB);
        $byCode = $byName = [];
        foreach ($onlyA as $k) {
            $byCode[MergeCandidates::normCode($k)][$k] = true;
            $name = MergeCandidates::normName($namesA[$k] ?? '');
            if (strlen($name) >= 6 && preg_match('/[A-Z]/', $name)) {
                $byName[$name][$k] = true;
            }
        }
        $found = $how = [];
        foreach ($onlyB as $k) {
            $code = MergeCandidates::normCode($k);
            $name = MergeCandidates::normName($namesB[$k] ?? '');
            $viaCode = $code !== '' ? array_keys($byCode[$code] ?? []) : [];
            $viaName = strlen($name) >= 6 ? array_keys($byName[$name] ?? []) : [];
            $all = array_values(array_unique(array_map('strval', [...$viaCode, ...$viaName])));
            if (count($all) === 1) {
                $found[$k] = $all[0];
                $how[$k] = $viaCode !== [] ? 'code' : 'name';
            }
        }
        $this->accept($found, $how);
    }

    /**
     * Garde les nouvelles paires de codes (cle B => cle A) sans conflit ni refus, et les applique aux bons de B.
     *
     * @param  array<string, string>  $found
     * @param  array<string, string>|string  $how
     */
    private function accept(array $found, array|string $how): int
    {
        $found = array_filter($found, fn ($a, $b) => ! isset($this->map[(string) $b]) && ! in_array($a, $this->map, true), ARRAY_FILTER_USE_BOTH);
        // deux codes de B vers le meme code de A : on ne sait pas lequel, aucun
        $targets = array_count_values(array_map('strval', $found));
        $found = array_filter($found, fn ($a) => $targets[(string) $a] === 1);
        if ($found === []) {
            return 0;
        }
        $refused = MergeDismissal::whereIn('pair', array_map(fn ($a, $b) => MergeDismissal::pair((string) $a, (string) $b), $found, array_keys($found)))
            ->pluck('pair')->flip();
        $kept = [];
        foreach ($found as $b => $a) {
            if (! $refused->has(MergeDismissal::pair((string) $a, (string) $b))) {
                $kept[(string) $b] = (string) $a;
                $this->learned[] = ['a' => (string) $a, 'b' => (string) $b, 'how' => is_array($how) ? $how[$b] : $how];
            }
        }
        if ($kept !== []) {
            $this->map += $kept;
            $this->b = array_map(fn (array $bon) => self::rekey($bon, $kept, $this->usage), $this->b);
        }

        return count($kept);
    }

    /** @return array<string, string> code_key => designation dans cette branche */
    private static function designations(int $branchId, array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_map('strval', $keys), 500) as $chunk) {
            BranchStock::query()->toBase()->where('branch_id', $branchId)->whereIn('code_key', $chunk)->whereNotNull('designation')
                ->get(['code_key', 'designation'])
                ->each(function ($s) use (&$out) {
                    $out[(string) $s->code_key] = trim((string) $s->designation);
                });
        }

        return $out;
    }

    /**
     * Ressemblance des articles de deux bons, de 0 a 1 : la part des quantites communes. null : un bon sans lignes.
     */
    private static function lineSimilarity(array $x, array $y): ?float
    {
        if ($x['lines'] === [] || $y['lines'] === []) {
            return null;
        }
        $common = $total = 0.0;
        foreach (array_keys($x['lines'] + $y['lines']) as $key) {
            $qx = $x['lines'][$key]['qty'] ?? 0.0;
            $qy = $y['lines'][$key]['qty'] ?? 0.0;
            if ($qx * $qy > 0) {
                $common += min(abs($qx), abs($qy));
            }
            $total += max(abs($qx), abs($qy));
        }
        if ($total > 0) {
            return round($common / $total, 6);
        }

        // quantites toutes a zero : les memes codes ?
        return array_keys($x['lines']) == array_keys($y['lines']) ? 1.0 : 0.0;
    }

    /**
     * Paires de bons : toutes les paires possibles a moins de WINDOW_DAYS jours ont un score (articles, montant, date),
     * puis on garde les meilleures, chaque bon une seule fois.
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: list<int>, 2: list<int>}
     */
    private static function matchBons(array $a, array $b): array
    {
        $candidates = [];
        $start = 0;
        $nb = count($b);
        foreach ($a as $i => $x) {
            while ($start < $nb && $b[$start]['day'] < $x['day'] - self::WINDOW_DAYS) {
                $start++;
            }
            for ($j = $start; $j < $nb && $b[$j]['day'] <= $x['day'] + self::WINDOW_DAYS; $j++) {
                $score = self::score($x, $b[$j]);
                if ($score !== null) {
                    $candidates[] = [$score, abs($b[$j]['day'] - $x['day']), $i, $j];
                }
            }
        }
        usort($candidates, fn ($p, $q) => [$q[0], $p[1], $p[2], $p[3]] <=> [$p[0], $q[1], $q[2], $q[3]]);

        $usedA = $usedB = $pairs = [];
        foreach ($candidates as [, , $i, $j]) {
            if (! isset($usedA[$i]) && ! isset($usedB[$j])) {
                $usedA[$i] = $usedB[$j] = true;
                $pairs[] = [$i, $j];
            }
        }
        usort($pairs, fn ($p, $q) => $p[0] <=> $q[0]);

        return [
            $pairs,
            array_values(array_filter(array_keys($a), fn ($i) => ! isset($usedA[$i]))),
            array_values(array_filter(array_keys($b), fn ($j) => ! isset($usedB[$j]))),
        ];
    }

    private static function score(array $x, array $y): ?float
    {
        $days = abs($y['day'] - $x['day']);
        $delta = abs($x['amount'] - $y['amount']);
        $exact = $delta < self::CENT;
        $amountSim = $exact ? 1.0 : max(0.0, 1 - $delta / max(abs($x['amount']), abs($y['amount']), 1.0));
        $dateSim = 1 - $days / (self::WINDOW_DAYS + 1);
        $lines = self::lineSimilarity($x, $y);

        if ($lines === null) {
            // sans lignes, seul le montant exact peut lier deux bons
            return $exact ? 0.75 + 0.25 * $dateSim : null;
        }
        if ($lines < 0.4 && ! $exact) {
            return null;
        }
        $score = 0.55 * $lines + 0.30 * $amountSim + 0.15 * $dateSim;

        // 0.30 + 0.15 vaut 0.4499999... en virgule flottante : une marge, sinon le meme montant le meme jour ne compte plus
        return $score >= self::MIN_SCORE - 1e-9 ? $score : null;
    }

    /**
     * Un bon d'un cote egal a deux ou trois bons de l'autre (meme total, a moins de WINDOW_DAYS jours) : saisi en
     * plusieurs fois. D'abord les paires au montant different qu'un bon reste complete, puis les restes entre eux.
     *
     * @return array{0: list<array{0: list<int>, 1: list<int>}>, 1: list<int>, 2: list<int>}
     */
    private static function splits(array $a, array $b, array &$pairs, array $restA, array $restB): array
    {
        $groups = [];
        foreach ($pairs as $k => [$i, $j]) {
            if (abs($a[$i]['amount'] - $b[$j]['amount']) < self::CENT) {
                continue;
            }
            if (($r = self::complement($b, $restB, $a[$i], $b[$j])) !== null) {
                $groups[] = [[$i], [$j, $r]];
                $restB = array_values(array_diff($restB, [$r]));
                unset($pairs[$k]);
            } elseif (($r = self::complement($a, $restA, $b[$j], $a[$i])) !== null) {
                $groups[] = [[$i, $r], [$j]];
                $restA = array_values(array_diff($restA, [$r]));
                unset($pairs[$k]);
            }
        }
        $pairs = array_values($pairs);
        foreach (self::splitSide($a, $b, $restA, $restB) as [$i, $two]) {
            $groups[] = [[$i], $two];
        }
        foreach (self::splitSide($b, $a, $restB, $restA) as [$j, $two]) {
            $groups[] = [$two, [$j]];
        }
        usort($groups, fn ($g, $h) => $g[0][0] <=> $h[0][0]);

        return [$groups, $restA, $restB];
    }

    /** Un bon reste de $side (indice) qui, ajoute a $part, fait exactement $whole, a moins de WINDOW_DAYS jours. */
    private static function complement(array $side, array $rest, array $whole, array $part): ?int
    {
        foreach ($rest as $r) {
            if (abs($side[$r]['day'] - $whole['day']) <= self::WINDOW_DAYS
                && abs($part['amount'] + $side[$r]['amount'] - $whole['amount']) < self::CENT) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Les bons restes de $one egaux a deux ou trois bons restes de $two ; ils sont retires de $left et $right.
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    private static function splitSide(array $one, array $two, array &$left, array &$right): array
    {
        $found = [];
        foreach ($left as $k => $i) {
            $near = array_slice(array_values(array_filter($right, fn ($j) => abs($two[$j]['day'] - $one[$i]['day']) <= self::WINDOW_DAYS)), 0, 40);
            $n = count($near);
            $target = $one[$i]['amount'];
            $group = null;
            for ($p = 0; $p < $n && $group === null; $p++) {
                for ($q = $p + 1; $q < $n && $group === null; $q++) {
                    $sum = $two[$near[$p]]['amount'] + $two[$near[$q]]['amount'];
                    if (abs($sum - $target) < self::CENT) {
                        $group = [$near[$p], $near[$q]];
                        break;
                    }
                    for ($r = $q + 1; $r < $n && $n <= 25; $r++) {
                        if (abs($sum + $two[$near[$r]]['amount'] - $target) < self::CENT) {
                            $group = [$near[$p], $near[$q], $near[$r]];
                            break;
                        }
                    }
                }
            }
            if ($group !== null) {
                $found[] = [$i, $group];
                unset($left[$k]);
                $right = array_values(array_diff($right, $group));
            }
        }
        $left = array_values($left);

        return $found;
    }

    /**
     * Un bon reste identique a un autre bon du meme cote, a 3 jours pres : saisi deux fois. Le jumeau est un bon
     * apparie, ou un reste plus ancien (le premier reste "manquant", le second est le doublon).
     *
     * @return array<int, int> indice => indice du jumeau
     */
    private static function duplicates(array $bons, array $rest): array
    {
        $isRest = array_fill_keys($rest, true);
        $out = [];
        foreach ($rest as $i) {
            if ($bons[$i]['lines'] === []) {
                continue;
            }
            foreach ($bons as $k => $other) {
                if ($k === $i || abs($other['day'] - $bons[$i]['day']) > 3 || abs($other['amount'] - $bons[$i]['amount']) >= self::CENT) {
                    continue;
                }
                if ((! isset($isRest[$k]) || $k < $i) && ! isset($out[$k]) && self::lineSimilarity($bons[$i], $other) === 1.0) {
                    $out[$i] = $k;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Les bons restes d'un cote retrouves de l'autre cote sous un AUTRE partenaire (meme articles et montant).
     *
     * @return array<int, array<string, mixed>> indice => le bon trouve
     */
    private function elsewhere(string $side, array $rest, int $branchId, string $kind, string $partnerKind, string $exclude): array
    {
        $bons = $side === 'a' ? $this->a : $this->b;
        $rest = array_values($rest);
        if ($rest === []) {
            return [];
        }
        $days = array_map(fn ($i) => $bons[$i]['day'], $rest);
        $from = Carbon::createFromTimestamp((min($days) - self::WINDOW_DAYS) * 86400)->toDateString();
        $to = Carbon::createFromTimestamp((max($days) + self::WINDOW_DAYS + 1) * 86400)->toDateString();
        $docs = Document::where('branch_id', $branchId)->where('kind', $kind)
            ->where(fn ($q) => $q->whereNull('partner_code')->orWhere('partner_code', '!=', $exclude))
            ->whereBetween('date', [$from, $to])
            ->get(['id', 'number', 'date', 'amount', 'partner_code']);
        // d'abord les en-tetes : montant a 2 % pres et date ; les lignes seulement pour ceux-la
        $headers = $docs->map(fn (Document $d) => ['id' => $d->id, 'day' => $d->date ? intdiv($d->date->getTimestamp(), 86400) : 0, 'amount' => (float) $d->amount]);
        $near = [];
        foreach ($rest as $i) {
            foreach ($headers as $h) {
                if (abs($h['day'] - $bons[$i]['day']) <= self::WINDOW_DAYS
                    && abs($h['amount'] - $bons[$i]['amount']) <= max(self::CENT, 0.02 * abs($bons[$i]['amount']))) {
                    $near[$i][] = $h['id'];
                }
            }
        }
        if ($near === []) {
            return [];
        }
        $wanted = array_unique(array_merge(...array_values($near)));
        $others = collect(self::withLines($docs->whereIn('id', $wanted)->values()))->keyBy('id');
        $this->canon = $this->canonicalKeys($others->all());
        $others = $others->map(function (array $bon) use ($side) {
            $bon = self::rekey($bon, $this->canon);

            // les bons trouves chez B prennent les codes de A appris a l'etape "codes"
            return $side === 'a' ? self::rekey($bon, $this->map) : $bon;
        });

        $candidates = [];
        foreach ($near as $i => $ids) {
            foreach ($ids as $id) {
                $o = $others[$id];
                $exact = abs($o['amount'] - $bons[$i]['amount']) < self::CENT;
                $sim = self::lineSimilarity($bons[$i], $o);
                if (($exact && ($sim === null || $sim >= 0.3)) || ($sim !== null && $sim >= 0.8)) {
                    $candidates[] = [$exact ? 0 : 1, -($sim ?? 0.5), abs($o['day'] - $bons[$i]['day']), $i, $id, $exact && ($sim ?? 0) >= 0.8];
                }
            }
        }
        sort($candidates);
        $names = Partner::where('branch_id', $branchId)->where('kind', $partnerKind)
            ->whereIn('code', array_map('strval', $docs->whereIn('id', $wanted)->pluck('partner_code')->filter()->unique()->values()->all()))
            ->pluck('name', 'code');
        $out = $used = [];
        foreach ($candidates as [, , $days, $i, $id, $sure]) {
            if (isset($out[$i]) || isset($used[$id])) {
                continue;
            }
            $used[$id] = true;
            $o = $others[$id];
            $code = (string) ($o['partner'] ?? '');
            $out[$i] = ['id' => $id, 'number' => $o['number'], 'date' => $o['date'], 'amount' => round($o['amount'], 2),
                'partner_code' => trim($code), 'partner' => $names[$code] ?? null, 'days' => $o['day'] - $bons[$i]['day'], 'sure' => $sure];
        }

        return $out;
    }

    private static function lineDiffs(array $x, array $y): array
    {
        $diffs = [];
        foreach (array_keys($x['lines'] + $y['lines']) as $key) {
            $la = $x['lines'][$key] ?? null;
            $lb = $y['lines'][$key] ?? null;
            $code = $la['code'] ?? $lb['code'];
            if ($lb === null) {
                $diffs[] = ['code' => $code, 'kind' => 'only_a', 'qty_a' => round($la['qty'], 2), 'total_a' => round($la['total'], 2)];
            } elseif ($la === null) {
                $diffs[] = ['code' => $code, 'kind' => 'only_b', 'qty_b' => round($lb['qty'], 2), 'total_b' => round($lb['total'], 2)];
            } else {
                $priceA = abs($la['qty']) > 0 ? $la['total'] / $la['qty'] : null;
                $priceB = abs($lb['qty']) > 0 ? $lb['total'] / $lb['qty'] : null;
                $kind = match (true) {
                    abs($la['qty'] - $lb['qty']) >= self::CENT => 'qty',
                    $priceA !== null && $priceB !== null && abs($priceA - $priceB) >= self::CENT => 'price',
                    abs($la['total'] - $lb['total']) >= self::CENT => 'total',
                    default => null,
                };
                if ($kind !== null) {
                    $diffs[] = ['code' => $code, 'kind' => $kind,
                        'qty_a' => round($la['qty'], 2), 'qty_b' => round($lb['qty'], 2),
                        'price_a' => $priceA === null ? null : round($priceA, 2), 'price_b' => $priceB === null ? null : round($priceB, 2),
                        'total_a' => round($la['total'], 2), 'total_b' => round($lb['total'], 2)];
                }
            }
        }

        return $diffs;
    }

    private static function ref(array $bon): array
    {
        return ['id' => $bon['id'], 'number' => $bon['number'], 'date' => $bon['date']];
    }

    private static function bonOut(array $bon): array
    {
        return self::ref($bon) + [
            'amount' => round($bon['amount'], 2),
            'lines' => array_values(array_map(fn (array $l) => [
                'code' => $l['code'], 'qty' => round($l['qty'], 2), 'total' => round($l['total'], 2),
                'price' => abs($l['qty']) > 0 ? round($l['total'] / $l['qty'], 2) : null,
            ], $bon['lines'])),
        ];
    }

    private static function side(Partner $p, array $bons, array $pays): array
    {
        return [
            'branch_id' => $p->branch_id,
            'branch' => $p->branch?->name,
            'partner_id' => $p->id,
            'partner' => $p->name ?? trim((string) $p->code),
            'code' => trim((string) $p->code),
            'bons' => count($bons),
            'amount' => round(array_sum(array_column($bons, 'amount')), 2),
            'payments' => round(array_sum(array_column($pays, 'amount')), 2),
            'opening' => round(array_sum(array_column(array_filter($pays, fn ($x) => $x['opening']), 'amount')), 2),
            'balance' => round(array_sum(array_column($bons, 'amount')) - array_sum(array_column($pays, 'amount')), 2),
            'first' => $bons[0]['date'] ?? null,
            'last' => $bons === [] ? null : $bons[count($bons) - 1]['date'],
        ];
    }

    /** Les articles a deux codes appris, avec leur designation de chaque cote et le nombre de bons concernes. */
    private function codesOut(int $branchA, int $branchB): array
    {
        if ($this->learned === []) {
            return [];
        }
        $namesA = self::designations($branchA, array_column($this->learned, 'a'));
        $namesB = self::designations($branchB, array_column($this->learned, 'b'));
        $raw = [];
        foreach (array_merge($this->a, $this->b) as $bon) {
            foreach ($bon['lines'] as $key => $line) {
                $raw[BranchStock::keyFor($line['code'])] ??= $line['code'];
            }
        }

        return array_map(fn (array $l) => [
            'a' => $raw[$l['a']] ?? $l['a'], 'b' => $raw[$l['b']] ?? $l['b'], 'how' => $l['how'],
            'name_a' => $namesA[$l['a']] ?? null, 'name_b' => $namesB[$l['b']] ?? null, 'bons' => $this->usage[$l['b']] ?? 0,
        ], $this->learned);
    }

    /**
     * Mois par mois : l'effet des problemes et le cumul ; le mois ou l'ecart est apparu.
     *
     * @return list<array{month: string, count: int, effect: float, running: float}>
     */
    private static function timeline(array $problems): array
    {
        $months = [];
        foreach ($problems as $p) {
            if (abs($p['effect']) < self::CENT) {
                continue;
            }
            $m = substr((string) ($p['date'] ?? ''), 0, 7) ?: '?';
            $months[$m] ??= ['month' => $m, 'count' => 0, 'effect' => 0.0];
            $months[$m]['count']++;
            $months[$m]['effect'] += $p['effect'];
        }
        ksort($months);
        $running = 0.0;
        $out = [];
        foreach ($months as $m) {
            $running += $m['effect'];
            $out[] = $m + ['running' => round($running, 2)];
            $out[count($out) - 1]['effect'] = round($m['effect'], 2);
        }

        return $out;
    }

    /**
     * Designation de chaque code des bons, prise dans le stock d'une des deux branches.
     *
     * @return array<string, string>
     */
    private static function names(array $bons, array $branchIds): array
    {
        $codes = [];
        foreach ($bons as $bon) {
            foreach ($bon['lines'] as $line) {
                $codes[BranchStock::keyFor($line['code'])] = $line['code'];
            }
        }
        if ($codes === []) {
            return [];
        }
        $names = [];
        // un code tout en chiffres ("1640") devient un entier comme cle de tableau : remis en texte, sinon MySQL compare
        // code_key a un nombre et "1640A" vaut 1640 (stockArtisana / Parck Alg, 07/11/2026). Une cle inconnue est ignoree.
        foreach (array_chunk(array_map('strval', array_keys($codes)), 500) as $chunk) {
            BranchStock::whereIn('branch_id', $branchIds)->whereIn('code_key', $chunk)->whereNotNull('designation')
                ->get(['code_key', 'designation'])
                ->each(function (BranchStock $s) use (&$names, $codes) {
                    $code = $codes[(string) $s->code_key] ?? null;
                    if ($code !== null) {
                        $names[$code] ??= trim((string) $s->designation);
                    }
                });
        }
        ksort($names);

        return $names;
    }

    // ---------- versements ----------

    /** @return list<array<string, mixed>> */
    private static function payments(Partner $partner): array
    {
        return Payment::where('branch_id', $partner->branch_id)->where('partner_kind', $partner->kind)->where('partner_code', $partner->code)
            ->orderBy('date')->orderBy('time')->orderBy('number')->orderBy('id')
            ->get(['id', 'number', 'date', 'type', 'amount', 'note'])
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'date' => $p->date?->format('Y-m-d'),
                'day' => $p->date ? intdiv($p->date->getTimestamp(), 86400) : 0,
                'type' => $p->type,
                'amount' => (float) $p->amount,
                'note' => $p->note,
                'opening' => (bool) preg_match('/solde\s*initial/i', (string) $p->note),
            ])->values()->all();
    }

    /**
     * Les soldes initiaux entre eux (le plus proche en date), puis les versements de meme montant a moins de
     * WINDOW_DAYS jours, puis ceux d'un montant different a moins de 2 jours (faute de frappe : au plus 25 % d'ecart).
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: list<int>, 2: list<int>}
     */
    private static function matchPayments(array $a, array $b): array
    {
        $pairs = [];
        $usedA = $usedB = [];
        $passes = [
            fn ($x, $y) => $x['opening'] && $y['opening'] && abs($x['day'] - $y['day']) <= 366,
            fn ($x, $y) => ! $x['opening'] && ! $y['opening'] && abs($x['amount'] - $y['amount']) < self::CENT && abs($x['day'] - $y['day']) <= self::WINDOW_DAYS,
            fn ($x, $y) => ! $x['opening'] && ! $y['opening'] && abs($x['day'] - $y['day']) <= 2
                && abs($x['amount'] - $y['amount']) <= 0.25 * max(abs($x['amount']), abs($y['amount'])),
        ];
        foreach ($passes as $fits) {
            $candidates = [];
            foreach ($a as $i => $x) {
                if (isset($usedA[$i])) {
                    continue;
                }
                foreach ($b as $j => $y) {
                    if (! isset($usedB[$j]) && $fits($x, $y)) {
                        $candidates[] = [abs($x['day'] - $y['day']), abs($x['amount'] - $y['amount']), $i, $j];
                    }
                }
            }
            sort($candidates);
            foreach ($candidates as [, , $i, $j]) {
                if (! isset($usedA[$i]) && ! isset($usedB[$j])) {
                    $usedA[$i] = $usedB[$j] = true;
                    $pairs[] = [$i, $j];
                }
            }
        }

        return [
            $pairs,
            array_values(array_filter(array_keys($a), fn ($i) => ! isset($usedA[$i]))),
            array_values(array_filter(array_keys($b), fn ($j) => ! isset($usedB[$j]))),
        ];
    }

    /**
     * Un versement sans pendant, et de l'autre cote un versement du meme montant a 3 jours pres sous un AUTRE
     * partenaire : peut-etre saisi au mauvais nom (jamais sur : un montant rond revient souvent).
     *
     * @return array<int, array<string, mixed>>
     */
    private function payElsewhere(array $pays, array $rest, int $branchId, string $partnerKind, string $exclude): array
    {
        $rest = array_values(array_filter($rest, fn ($i) => ! $pays[$i]['opening'] && abs($pays[$i]['amount']) >= self::CENT));
        if ($rest === []) {
            return [];
        }
        $days = array_map(fn ($i) => $pays[$i]['day'], $rest);
        $rows = Payment::where('branch_id', $branchId)->where('partner_kind', $partnerKind)->where('partner_code', '!=', $exclude)
            ->whereBetween('date', [Carbon::createFromTimestamp((min($days) - 3) * 86400)->toDateString(), Carbon::createFromTimestamp((max($days) + 4) * 86400)->toDateString()])
            ->whereIn('amount', array_values(array_unique(array_map(fn ($i) => round($pays[$i]['amount'], 2), $rest))))
            ->get(['id', 'number', 'date', 'amount', 'partner_code']);
        $names = Partner::where('branch_id', $branchId)->where('kind', $partnerKind)
            ->whereIn('code', array_map('strval', $rows->pluck('partner_code')->filter()->unique()->values()->all()))->pluck('name', 'code');
        $out = $used = [];
        foreach ($rest as $i) {
            foreach ($rows as $r) {
                $day = $r->date ? intdiv($r->date->getTimestamp(), 86400) : 0;
                if (! isset($used[$r->id]) && abs($day - $pays[$i]['day']) <= 3 && abs((float) $r->amount - $pays[$i]['amount']) < self::CENT) {
                    $used[$r->id] = true;
                    $code = (string) $r->partner_code;
                    $out[$i] = ['payment' => true, 'id' => $r->id, 'number' => $r->number, 'date' => $r->date?->format('Y-m-d'),
                        'amount' => round((float) $r->amount, 2), 'partner_code' => trim($code), 'partner' => $names[$code] ?? null,
                        'days' => $day - $pays[$i]['day'], 'sure' => false];
                    break;
                }
            }
        }

        return $out;
    }

    private static function payOut(array $p): array
    {
        return ['payment' => true, 'id' => $p['id'], 'number' => $p['number'], 'date' => $p['date'], 'type' => $p['type'],
            'amount' => round($p['amount'], 2), 'opening' => $p['opening'], 'note' => $p['note']];
    }

    /**
     * Ce qui a change depuis l'analyse precedente de la meme paire. Un probleme = son type et les bons / versements qu'il
     * touche (problemKey). Chaque probleme d'aujourd'hui : "still" (deja la), "changed" (ses bons etaient dans un autre
     * probleme : corrige a moitie, ou autrement) ou "new". Ceux d'avant dont aucun bon ni versement n'est plus en cause :
     * "fixed", listes avec l'ecart d'avant.
     *
     * @param  array<string, mixed>|null  $previous  le rapport precedent
     * @param  array<string, mixed>  $meta  id et date de l'analyse precedente
     * @return array<string, mixed>
     */
    public static function compare(array $report, ?array $previous, array $meta = []): array
    {
        if ($previous === null || ! isset($previous['problems'])) {
            return $report;
        }
        $ids = fn (array $p) => array_map(fn (array $x) => (empty($x['payment']) ? 'd' : 'p').$x['id'], [...$p['a'], ...$p['b']]);
        $old = $oldIds = [];
        foreach ($previous['problems'] as $p) {
            $old[self::problemKey($p)] = $p;
            foreach ($ids($p) as $id) {
                $oldIds[$id] = true;
            }
        }
        $seen = $nowIds = [];
        $count = ['still' => 0, 'changed' => 0, 'new' => 0];
        foreach ($report['problems'] as $k => $p) {
            $key = self::problemKey($p);
            $mine = $ids($p);
            $status = isset($old[$key]) ? 'still' : (array_intersect_key($oldIds, array_flip($mine)) !== [] ? 'changed' : 'new');
            $report['problems'][$k]['status'] = $status;
            $count[$status]++;
            $seen[$key] = true;
            foreach ($mine as $id) {
                $nowIds[$id] = true;
            }
        }
        $fixed = [];
        foreach ($old as $key => $p) {
            if (! isset($seen[$key]) && array_intersect_key($nowIds, array_flip($ids($p))) === []) {
                unset($p['lines']);
                $fixed[] = $p;
            }
        }
        $report['since'] = $meta + [
            'gap' => $previous['gap'] ?? null,
            'problems' => count($previous['problems']) + ($previous['more_problems'] ?? 0),
            'fixed' => count($fixed),
        ] + $count;
        $report['fixed'] = array_slice($fixed, 0, self::MAX_LISTED);

        return $report;
    }

    /** Un probleme d'un rapport a l'autre : son type, et les bons (d) et versements (p) de chaque cote. */
    public static function problemKey(array $p): string
    {
        $ids = fn (array $xs) => implode(',', array_map(fn (array $x) => (empty($x['payment']) ? 'd' : 'p').$x['id'], $xs));

        return $p['type'].'|'.$ids($p['a']).'|'.$ids($p['b']);
    }

    /**
     * Les deux partenaires se correspondent-ils pour ce compte ? A et B differentes, un client chez A, un fournisseur
     * chez B, et le compte voit les ventes des deux branches.
     */
    public static function allowed(Partner $customer, Partner $supplier, ?array $salesIds): bool
    {
        $ids = $salesIds === null ? null : array_map('intval', $salesIds);
        $a = (int) $customer->branch_id;
        $b = (int) $supplier->branch_id;

        return $customer->kind === 'customer' && $supplier->kind === 'supplier' && $a !== $b
            && ($ids === null || (in_array($a, $ids, true) && in_array($b, $ids, true)))
            && Branch::whereIn('id', [$a, $b])->where('active', true)->count() === 2;
    }
}
