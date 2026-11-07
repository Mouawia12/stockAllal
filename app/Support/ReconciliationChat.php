<?php

namespace App\Support;

/**
 * Le chat de "المطابقة" : l'IA connectee (page "الذكاء الاصطناعي") explique le rapport de Reconciliation, rien de plus.
 * Elle recoit le rapport sans aucun nom : les branches sont {A} (qui vend) et {B} (qui achete), les bons {A:101} et
 * {B:7}, et la page remet les vrais noms et les liens (reconcile/index). Elle recoit les numeros, dates, montants,
 * codes, designations, quantites et prix des bons des deux comptes : c'est ce qu'il faut pour les comparer.
 * Le texte systeme ne change pas d'une question a l'autre (meme rapport) : Claude le garde en cache.
 */
final class ReconciliationChat
{
    /** Messages gardes par conversation (la page coupe les plus anciens) et longueur d'une question. */
    public const MAX_MESSAGES = 20;

    public const MAX_CHARS = 2000;

    /** Les bons et versements identiques envoyes a l'IA (les plus recents) : de quoi repondre "ou est le bon X ?". */
    private const MAX_SAME = 150;

    /** Lignes d'un bon envoyees a l'IA. */
    private const MAX_LINES = 40;

    private const LANGUAGES = [
        'ar' => 'Arabic. The user often writes in Algerian Arabic (Darja): answer in simple, clear Arabic; Gescomm words (bon, versement, solde) may stay in French',
        'fr' => 'French',
        'en' => 'English',
    ];

    public static function system(array $report, string $locale): string
    {
        $language = self::LANGUAGES[$locale] ?? self::LANGUAGES['en'];
        $json = json_encode(self::data($report), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return <<<TXT
You help the owner of a chain of shops in Algeria find why two branches' accounts with each other do not agree. Both branches use the Gescomm software.

Branch {A} sells goods to branch {B}. In {A}'s books, {B} is a customer; in {B}'s books, {A} is a supplier. Every sale bon {A} makes to this customer should exist in {B} as a purchase bon from this supplier: a different number, the same or a close date, the same items, quantities, prices and amount. Every payment {B} makes should exist in {A} as a payment received. When both sides are complete, {A}'s balance (what the customer owes) equals {B}'s balance (what {B} owes the supplier).

A program already compared both sides, by items, amounts and dates, never by bon numbers. Its report is the JSON below and it is your only source of truth. Never invent a bon, a payment, an amount or a date that is not in it; if the user asks about something the report does not contain, say so.

Reading the report:
- "gap" = {A}'s balance minus {B}'s balance. Each problem has an "effect": how much it moves the gap. The effects add up to the gap.
- Problem types:
  - bon_missing_b: a sale bon in {A} with no purchase bon in {B} (never entered in {B}, or entered for another supplier).
  - bon_missing_a: a purchase bon in {B} with no sale bon in {A}.
  - bon_amount: the same bon on both sides with different amounts; "line_diffs" says which items differ: only_a (item only in {A}'s bon), only_b (only in {B}'s), qty, price or total.
  - bon_lines: the same amount but different items.
  - bon_split: one bon on one side was entered as two bons on the other; no money difference.
  - pay_missing_b: a payment {A} received that {B} did not record.
  - pay_missing_a: a payment {B} recorded that {A} did not receive.
  - pay_amount: the same payment with different amounts.
  - opening: the opening balances ("Solde Initial", a negative "Autre" payment) differ, or exist on one side only.
- "days" on a pair: how many days after {A} the other side entered it.
- "items" gives the designation of each item code that appears in a problem.

How to answer:
- Answer in {$language}. Be short and practical: what is wrong, in which branch, and what to enter or correct there. Start with the biggest effects.
- Write the branches only as {A} and {B}, and bons only as {A:number} or {B:number}, exactly like that: the page turns them into the real names and links. The names of the branches and partners are hidden on purpose; never ask for them.
- Amounts with 2 decimals and a space between thousands (1 250 000,00).
- Markdown: short paragraphs, "- " lists and **bold** only. No tables, no headings.
- If the gap is 0 and there is no problem, say the accounts agree.

Report:
{$json}
TXT;
    }

    /**
     * Le rapport tel que l'IA le recoit : sans noms de branche ni de partenaire, ni identifiants internes.
     *
     * @return array<string, mixed>
     */
    public static function data(array $r): array
    {
        $codes = [];
        $problems = array_map(function (array $p) use (&$codes) {
            $out = ['type' => $p['type'], 'effect' => $p['effect'], 'date' => $p['date']];
            if (isset($p['days'])) {
                $out['days'] = $p['days'];
            }
            foreach (['a' => 'A', 'b' => 'B'] as $side => $label) {
                $out[$label] = array_map(fn (array $x) => self::item($x, $label, $codes), $p[$side]);
            }
            if (! empty($p['lines'])) {
                $out['line_diffs'] = $p['lines'];
                foreach ($p['lines'] as $l) {
                    $codes[$l['code']] = true;
                }
            }

            return $out;
        }, $r['problems']);

        return [
            'A' => [
                'sale_bons' => $r['a']['bons'], 'sales_total' => $r['a']['amount'], 'payments_received_total' => $r['a']['payments'],
                'opening_balance' => $r['a']['opening'], 'balance' => $r['a']['balance'], 'first_bon' => $r['a']['first'], 'last_bon' => $r['a']['last'],
            ],
            'B' => [
                'purchase_bons' => $r['b']['bons'], 'purchases_total' => $r['b']['amount'], 'payments_made_total' => $r['b']['payments'],
                'opening_balance' => $r['b']['opening'], 'balance' => $r['b']['balance'], 'first_bon' => $r['b']['first'], 'last_bon' => $r['b']['last'],
            ],
            'gap' => $r['gap'],
            'counts' => $r['counts'],
            'problems' => $problems,
            'more_problems_not_listed' => $r['more_problems'],
            'identical_bons' => array_map(fn (array $s) => [
                'A' => '{A:'.$s['a']['number'].'}', 'B' => '{B:'.$s['b']['number'].'}', 'date' => $s['a']['date'], 'amount' => $s['amount'], 'days' => $s['days'],
            ], array_slice($r['same'], 0, self::MAX_SAME)),
            'identical_payments' => array_map(fn (array $s) => [
                'date' => $s['a']['date'], 'amount' => $s['a']['amount'], 'days' => $s['days'],
            ], array_slice($r['pay_same'], 0, self::MAX_SAME)),
            'items' => array_intersect_key($r['names'], $codes),
        ];
    }

    /** Un bon ({A:101}, ses lignes) ou un versement du rapport. */
    private static function item(array $x, string $label, array &$codes): array
    {
        if (! empty($x['payment'])) {
            return array_filter([
                'payment' => $x['number'], 'date' => $x['date'], 'type' => $x['type'], 'amount' => $x['amount'],
                'note' => $x['opening'] ? $x['note'] : null,
            ], fn ($v) => $v !== null);
        }
        foreach ($x['lines'] as $l) {
            $codes[$l['code']] = true;
        }

        return [
            'bon' => '{'.$label.':'.$x['number'].'}', 'date' => $x['date'], 'amount' => $x['amount'],
            'lines' => array_map(fn (array $l) => [$l['code'], $l['qty'], $l['price'], $l['total']], array_slice($x['lines'], 0, self::MAX_LINES)),
            'more_lines' => max(0, count($x['lines']) - self::MAX_LINES),
        ];
    }

    /**
     * Les messages recus de la page, propres : user / assistant en alternance, le premier et le dernier de l'utilisateur,
     * les plus anciens coupes au-dela de MAX_MESSAGES. null : pas de question.
     *
     * @return list<array{role: string, content: string}>|null
     */
    public static function messages(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $m) {
            $role = $m['role'] ?? null;
            $text = trim((string) ($m['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $text === '') {
                continue;
            }
            $text = mb_substr($text, 0, $role === 'user' ? self::MAX_CHARS : 20000);
            if ($out !== [] && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n".$text;
            } else {
                $out[] = ['role' => $role, 'content' => $text];
            }
        }
        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        while (count($out) > self::MAX_MESSAGES) {
            array_splice($out, 0, 2);
        }

        return $out !== [] && $out[count($out) - 1]['role'] === 'user' ? $out : null;
    }
}
