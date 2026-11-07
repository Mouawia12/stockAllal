<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

/**
 * "المطابقة" entre deux branches qui commercent : A vend a B. Chez A, B est un client (GC002) ; chez B, A est un
 * fournisseur (GC004). Chaque bon de vente de A a ce client devrait exister chez B comme bon d'achat de ce fournisseur
 * (autre numero, meme jour ou quelques jours apres, memes articles, quantites, prix et montant), et chaque versement
 * de B devrait exister chez A. Les deux soldes seraient alors egaux.
 *
 * Ici, sans IA : les bons sont apparies par leurs articles, leur montant et leur date (jamais par le numero), puis les
 * versements par montant et date. Chaque ecart est un "probleme" avec son effet sur (solde A - solde B) : la somme des
 * effets redonne exactement l'ecart des soldes. Solde = bons - versements, tous types, "Autre" avec son signe ; le
 * solde initial est un versement "Autre" negatif "Solde Initial ..." (agent/tools/check-balances.ps1, 04/11/2026).
 * L'IA (ReconciliationChat) ne fait qu'expliquer ce rapport.
 */
final class Reconciliation
{
    /** Ecart maximal entre la date d'un bon chez A et chez B (achat saisi en retard, ou avant). */
    public const WINDOW_DAYS = 31;

    /** Un bon ou un versement par categorie dans le rapport : au-dela, "et N autres". */
    public const MAX_LISTED = 400;

    private const CENT = 0.005;

    /** Score minimal d'une paire de bons (0 a 1). */
    private const MIN_SCORE = 0.45;

    /**
     * @return array<string, mixed>
     */
    public static function run(Partner $customer, Partner $supplier): array
    {
        $a = self::bons($customer, 'sale');
        $b = self::bons($supplier, 'purchase');
        $canon = self::canonicalKeys($a->merge($b));
        $a = $a->map(fn (array $bon) => self::canonicalize($bon, $canon))->values()->all();
        $b = $b->map(fn (array $bon) => self::canonicalize($bon, $canon))->values()->all();

        $problems = [];
        [$pairs, $restA, $restB] = self::matchBons($a, $b);
        [$splits, $restA, $restB] = self::splits($a, $b, $pairs, $restA, $restB);

        $same = [];
        foreach ($pairs as [$i, $j]) {
            $diffs = self::lineDiffs($a[$i], $b[$j]);
            $delta = round($a[$i]['amount'] - $b[$j]['amount'], 2);
            $days = $b[$j]['day'] - $a[$i]['day'];
            if (abs($delta) < self::CENT && $diffs === []) {
                $same[] = ['a' => self::ref($a[$i]), 'b' => self::ref($b[$j]), 'amount' => $a[$i]['amount'], 'days' => $days];

                continue;
            }
            $problems[] = [
                'type' => abs($delta) < self::CENT ? 'bon_lines' : 'bon_amount',
                'effect' => $delta,
                'date' => $a[$i]['date'],
                'days' => $days,
                'a' => [self::bonOut($a[$i])],
                'b' => [self::bonOut($b[$j])],
                'lines' => $diffs,
            ];
        }
        foreach ($splits as [$is, $js]) {
            $sumA = array_sum(array_map(fn ($i) => $a[$i]['amount'], $is));
            $sumB = array_sum(array_map(fn ($j) => $b[$j]['amount'], $js));
            $problems[] = [
                'type' => 'bon_split',
                'effect' => round($sumA - $sumB, 2),
                'date' => $a[$is[0]]['date'],
                'a' => array_map(fn ($i) => self::bonOut($a[$i]), $is),
                'b' => array_map(fn ($j) => self::bonOut($b[$j]), $js),
            ];
        }
        foreach ($restA as $i) {
            $problems[] = ['type' => 'bon_missing_b', 'effect' => round($a[$i]['amount'], 2), 'date' => $a[$i]['date'], 'a' => [self::bonOut($a[$i])], 'b' => []];
        }
        foreach ($restB as $j) {
            $problems[] = ['type' => 'bon_missing_a', 'effect' => round(-$b[$j]['amount'], 2), 'date' => $b[$j]['date'], 'a' => [], 'b' => [self::bonOut($b[$j])]];
        }

        $payA = self::payments($customer);
        $payB = self::payments($supplier);
        [$payPairs, $payProblems] = self::matchPayments($payA, $payB);
        $problems = array_merge($problems, $payProblems);

        // le plus gros effet d'abord ; a effet egal (0 : articles, bons regroupes), le plus recent
        usort($problems, fn (array $x, array $y) => [abs($y['effect']), $y['date'] ?? ''] <=> [abs($x['effect']), $x['date'] ?? '']);

        $side = fn (Partner $p, array $bons, array $pays) => [
            'branch_id' => $p->branch_id,
            'branch' => $p->branch?->name,
            'partner_id' => $p->id,
            'partner' => $p->name ?? $p->code,
            'code' => trim((string) $p->code),
            'bons' => count($bons),
            'amount' => round(array_sum(array_column($bons, 'amount')), 2),
            'payments' => round(array_sum(array_column($pays, 'amount')), 2),
            'opening' => round(array_sum(array_column(array_filter($pays, fn ($x) => $x['opening']), 'amount')), 2),
            'balance' => round(array_sum(array_column($bons, 'amount')) - array_sum(array_column($pays, 'amount')), 2),
            'first' => $bons[0]['date'] ?? null,
            'last' => $bons === [] ? null : $bons[count($bons) - 1]['date'],
        ];
        $sideA = $side($customer, $a, $payA);
        $sideB = $side($supplier, $b, $payB);
        $count = fn (string $type) => count(array_filter($problems, fn ($p) => $p['type'] === $type));

        return [
            'a' => $sideA,
            'b' => $sideB,
            'gap' => round($sideA['balance'] - $sideB['balance'], 2),
            'explained' => round(array_sum(array_column($problems, 'effect')), 2),
            'counts' => [
                'same' => count($same),
                'bon_amount' => $count('bon_amount'),
                'bon_lines' => $count('bon_lines'),
                'bon_split' => $count('bon_split'),
                'bon_missing_b' => $count('bon_missing_b'),
                'bon_missing_a' => $count('bon_missing_a'),
                'pay_same' => count($payPairs),
                'pay_amount' => $count('pay_amount'),
                'pay_missing_b' => $count('pay_missing_b'),
                'pay_missing_a' => $count('pay_missing_a'),
                'opening' => $count('opening'),
            ],
            'problems' => array_slice($problems, 0, self::MAX_LISTED),
            'more_problems' => max(0, count($problems) - self::MAX_LISTED),
            // les plus recents d'abord
            'same' => array_slice(array_reverse($same), 0, self::MAX_LISTED),
            'pay_same' => array_slice(array_reverse($payPairs), 0, self::MAX_LISTED),
            'names' => self::names(array_merge($a, $b), [$customer->branch_id, $supplier->branch_id]),
        ];
    }

    // ---------- bons ----------

    /**
     * Les bons d'un partenaire, du plus ancien au plus recent, avec leurs lignes par code.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function bons(Partner $partner, string $kind): Collection
    {
        return Document::where('branch_id', $partner->branch_id)->where('kind', $kind)->where('partner_code', $partner->code)
            ->with('lines:id,document_id,position,code_raw,code_key,qty,price,total')
            ->orderBy('date')->orderBy('time')->orderBy('number')->orderBy('id')
            ->get(['id', 'number', 'date', 'time', 'amount'])
            ->map(function (Document $d) {
                $lines = [];
                foreach ($d->lines as $l) {
                    $key = (string) $l->code_key;
                    $lines[$key] ??= ['code' => trim((string) $l->code_raw), 'qty' => 0.0, 'total' => 0.0];
                    $lines[$key]['qty'] += (float) $l->qty;
                    $lines[$key]['total'] += (float) $l->total;
                }

                return [
                    'id' => $d->id,
                    'number' => $d->number,
                    'date' => $d->date?->format('Y-m-d'),
                    'day' => $d->date ? intdiv($d->date->getTimestamp(), 86400) : 0,
                    'amount' => (float) $d->amount,
                    'lines' => $lines,
                ];
            });
    }

    /**
     * Les codes fusionnes ("دمج الأصناف") comptent pour un seul article : 820AC chez A = 820-AC chez B.
     *
     * @return array<string, string> code_key => cle commune
     */
    private static function canonicalKeys(Collection $bons): array
    {
        $keys = $bons->flatMap(fn (array $bon) => array_keys($bon['lines']))->unique()->values();

        return array_map(fn (array $members) => $members[0], ProductGroup::expand($keys));
    }

    private static function canonicalize(array $bon, array $canon): array
    {
        $lines = [];
        foreach ($bon['lines'] as $key => $line) {
            $c = $canon[$key] ?? $key;
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
            return $common / $total;
        }

        // quantites toutes a zero : les memes codes ?
        return array_keys($x['lines']) === array_keys($y['lines']) ? 1.0 : 0.0;
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

        return $score >= self::MIN_SCORE ? $score : null;
    }

    /**
     * Un bon d'un cote egal a deux bons de l'autre (meme total, a moins de WINDOW_DAYS jours) : saisi en deux fois.
     *
     * @return array{0: list<array{0: list<int>, 1: list<int>}>, 1: list<int>, 2: list<int>}
     */
    private static function splits(array $a, array $b, array &$pairs, array $restA, array $restB): array
    {
        $groups = [];
        // une paire au montant different + un bon reste qui complete exactement : un bon saisi en deux fois
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
     * Les bons restes de $one egaux a deux bons restes de $two ; ils sont retires de $left et $right.
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    private static function splitSide(array $one, array $two, array &$left, array &$right): array
    {
        $found = [];
        foreach ($left as $k => $i) {
            $near = array_slice(array_values(array_filter($right, fn ($j) => abs($two[$j]['day'] - $one[$i]['day']) <= self::WINDOW_DAYS)), 0, 60);
            $pair = null;
            for ($p = 0; $p < count($near) && $pair === null; $p++) {
                for ($q = $p + 1; $q < count($near); $q++) {
                    if (abs($two[$near[$p]]['amount'] + $two[$near[$q]]['amount'] - $one[$i]['amount']) < self::CENT) {
                        $pair = [$near[$p], $near[$q]];
                        break;
                    }
                }
            }
            if ($pair !== null) {
                $found[] = [$i, $pair];
                unset($left[$k]);
                $right = array_values(array_diff($right, $pair));
            }
        }
        $left = array_values($left);

        return $found;
    }

    /**
     * Ce qui differe entre deux bons apparies, article par article.
     *
     * @return list<array<string, mixed>>
     */
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
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
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

        $same = $problems = [];
        foreach ($pairs as [$i, $j]) {
            $delta = round($a[$i]['amount'] - $b[$j]['amount'], 2);
            if (abs($delta) < self::CENT) {
                $same[] = ['a' => self::payOut($a[$i]), 'b' => self::payOut($b[$j]), 'days' => $b[$j]['day'] - $a[$i]['day']];

                continue;
            }
            // un versement de plus chez A baisse le solde de A : effet = -(A - B)
            $problems[] = ['type' => $a[$i]['opening'] ? 'opening' : 'pay_amount', 'effect' => -$delta, 'date' => $a[$i]['date'],
                'days' => $b[$j]['day'] - $a[$i]['day'], 'a' => [self::payOut($a[$i])], 'b' => [self::payOut($b[$j])]];
        }
        foreach ($a as $i => $x) {
            if (! isset($usedA[$i])) {
                $problems[] = ['type' => $x['opening'] ? 'opening' : 'pay_missing_b', 'effect' => round(-$x['amount'], 2), 'date' => $x['date'], 'a' => [self::payOut($x)], 'b' => []];
            }
        }
        foreach ($b as $j => $y) {
            if (! isset($usedB[$j])) {
                $problems[] = ['type' => $y['opening'] ? 'opening' : 'pay_missing_a', 'effect' => round($y['amount'], 2), 'date' => $y['date'], 'a' => [], 'b' => [self::payOut($y)]];
            }
        }
        usort($same, fn ($x, $y) => $x['a']['date'] <=> $y['a']['date']);

        return [$same, $problems];
    }

    private static function payOut(array $p): array
    {
        return ['payment' => true, 'id' => $p['id'], 'number' => $p['number'], 'date' => $p['date'], 'type' => $p['type'],
            'amount' => round($p['amount'], 2), 'opening' => $p['opening'], 'note' => $p['note']];
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
