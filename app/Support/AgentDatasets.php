<?php

namespace App\Support;

use App\Models\BranchStock;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Les donnees que l'agent 1.0.7 envoie en plus du stock (agent/README.md, "POST /agent/digest" et "POST /agent/records"),
 * et la caisse depuis 1.0.8.
 * Chaque jeu va dans une table partagee avec la future branche approuvee, borne par "scope" (ventes et achats dans
 * documents...). dated : les groupes sont des jours (YYYY-MM-DD), sinon des paquets choisis par l'agent (clients).
 * Les champs recus sont tous facultatifs sauf key et digest : une colonne absente d'une version de Gescomm arrive vide.
 */
final class AgentDatasets
{
    public const ALL = [
        'sales' => ['table' => 'documents', 'scope' => ['kind' => 'sale'], 'dated' => true, 'lines' => true],
        'purchases' => ['table' => 'documents', 'scope' => ['kind' => 'purchase'], 'dated' => true, 'lines' => true],
        'customers' => ['table' => 'partners', 'scope' => ['kind' => 'customer'], 'dated' => false, 'lines' => false],
        'suppliers' => ['table' => 'partners', 'scope' => ['kind' => 'supplier'], 'dated' => false, 'lines' => false],
        'customer_payments' => ['table' => 'payments', 'scope' => ['partner_kind' => 'customer'], 'dated' => true, 'lines' => false],
        'supplier_payments' => ['table' => 'payments', 'scope' => ['partner_kind' => 'supplier'], 'dated' => true, 'lines' => false],
        // agent 1.0.8 : la caisse, entrees (C008) et sorties (C002)
        'cash_in' => ['table' => 'cash_entries', 'scope' => ['direction' => 'in'], 'dated' => true, 'lines' => false],
        'cash_out' => ['table' => 'cash_entries', 'scope' => ['direction' => 'out'], 'dated' => true, 'lines' => false],
    ];

    /** Empreinte d'une ligne : 48 bits (12 chiffres hexadecimaux d'un SHA-1), la somme d'un groupe tient dans 64 bits. */
    public const DIGEST_MAX = 281474976710655;

    /** @return array{table: string, scope: array<string, string>, dated: bool, lines: bool}|null */
    public static function get(string $name): ?array
    {
        return self::ALL[$name] ?? null;
    }

    /** Un groupe valide : un jour reel pour un jeu date, sinon 1 a 20 caracteres simples. */
    public static function validGroup(array $set, mixed $group): bool
    {
        if (! is_string($group)) {
            return false;
        }
        if (! $set['dated']) {
            return preg_match('/^[A-Za-z0-9_-]{1,20}$/', $group) === 1;
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $group, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Colonnes de la table pour un enregistrement recu (sans les colonnes de synchro).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function row(array $set, array $record, string $group): array
    {
        $date = $set['dated'] ? $group : null;

        return match ($set['table']) {
            'documents' => [
                'number' => self::int($record['number'] ?? null),
                'date' => $date,
                'time' => self::time($record['time'] ?? null),
                'partner_code' => self::text($record['partner'] ?? null, 20, false),
                'amount' => self::number($record['amount'] ?? null),
                'discount' => self::number($record['discount'] ?? null),
                'gross' => self::number($record['gross'] ?? null),
                'payment' => self::text($record['payment'] ?? null, 20),
                'entered_by' => self::text($record['entered_by'] ?? null, 30),
                'served_by' => self::text($record['served_by'] ?? null, 100),
                'credit_days' => self::int($record['credit_days'] ?? null),
                'note' => self::text($record['note'] ?? null, 255),
            ],
            'partners' => [
                'code' => self::text($record['code'] ?? $record['key'], 20, false),
                // MONCLIENT / MONFOURNISSEUR, sinon l'ancienne colonne courte (CLIENT / FOURNISSEUR)
                'name' => self::text($record['name'] ?? null, 200) ?? self::text($record['name_alt'] ?? null, 200),
                'phone' => self::text($record['phone'] ?? null, 100),
                'mobile' => self::text($record['mobile'] ?? null, 60),
                'address' => self::text($record['address'] ?? null, 255) ?? self::text($record['address_alt'] ?? null, 255),
                'wilaya' => self::text($record['wilaya'] ?? null, 100),
                'contact' => self::text($record['contact'] ?? null, 60),
                'activity' => self::text($record['activity'] ?? null, 100),
                'type' => self::text($record['type'] ?? null, 40),
                'opening_balance' => self::number($record['opening_balance'] ?? null),
                'credit_limit' => self::number($record['credit_limit'] ?? null),
                'credit_days' => self::int($record['credit_days'] ?? null),
                'blocked' => self::yes($record['blocked'] ?? null),
                'nif' => self::text($record['nif'] ?? null, 100),
                'nis' => self::text($record['nis'] ?? null, 100),
                'rc' => self::text($record['rc'] ?? null, 100),
                'ai' => self::text($record['ai'] ?? null, 100),
            ],
            'payments' => [
                'partner_code' => self::text($record['partner'] ?? null, 20, false),
                'date' => $date,
                'number' => self::int($record['number'] ?? null),
                'time' => self::time($record['time'] ?? null),
                'type' => self::text($record['type'] ?? null, 20),
                'amount' => self::number($record['amount'] ?? null),
                'cheque' => self::text($record['cheque'] ?? null, 60),
                'cheque_state' => self::text($record['cheque_state'] ?? null, 10),
                'paid_on' => self::date($record['paid_on'] ?? null),
                'document_number' => self::int($record['document_number'] ?? null),
                'note' => self::text($record['note'] ?? null, 255),
                'entered_by' => self::text($record['entered_by'] ?? null, 30),
            ],
            'cash_entries' => [
                'date' => $date,
                'number' => self::int($record['number'] ?? null),
                'time' => self::time($record['time'] ?? null),
                'amount' => self::number($record['amount'] ?? null),
                'label' => self::text($record['label'] ?? null, 250),
                'category' => self::text($record['category'] ?? null, 40),
                'category_code' => self::text($record['category_code'] ?? null, 20),
                'expense' => self::yes($record['expense'] ?? null),
                'counter' => self::yes($record['counter'] ?? null),
                'person' => self::text($record['person'] ?? null, 60),
                'type' => self::text($record['type'] ?? null, 20),
                'cheque' => self::text($record['cheque'] ?? null, 60),
                'payment_number' => self::int($record['payment_number'] ?? null),
                'document_number' => self::int($record['document_number'] ?? null),
                'note' => self::text($record['note'] ?? null, 255),
                'entered_by' => self::text($record['entered_by'] ?? null, 30),
            ],
        };
    }

    /**
     * Lignes d'un document : sans code, une ligne est ignoree.
     *
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    public static function lines(array $record): array
    {
        $rows = [];
        foreach (is_array($record['lines'] ?? null) ? $record['lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $code = rtrim((string) ($line['code'] ?? ''));
            if ($code === '' || mb_strlen($code) > 30) {
                continue;
            }
            $rows[] = [
                'position' => self::int($line['position'] ?? null),
                'code_raw' => $code,
                'code_key' => BranchStock::keyFor($code),
                'qty' => self::number($line['qty'] ?? null),
                'price' => self::number($line['price'] ?? null),
                'discount' => self::number($line['discount'] ?? null),
                'total' => self::number($line['total'] ?? null),
                'cost' => self::number($line['cost'] ?? null),
                'vat' => self::number($line['vat'] ?? null),
            ];
        }

        return $rows;
    }

    private static function number(mixed $value): int|float|string|null
    {
        return is_numeric($value) && abs((float) $value) < 1e13 ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && abs((float) $value) < 1e15 ? (int) $value : null;
    }

    /** Texte coupe a la taille de la colonne ; vide = null. $trim = false : un code garde ses espaces de debut. */
    private static function text(mixed $value, int $max, bool $trim = true): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = $trim ? trim((string) $value) : rtrim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function time(mixed $value): ?string
    {
        // isql ecrit 09:18:33.0000
        return is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)
            ? sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0)
            : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /** "Oui" / "Non" de Gescomm (Bloqué?), "O" / "N" (UNECHARGE), 1 / 0 (COMPTOIR) ; autre chose = inconnu. */
    private static function yes(mixed $value): ?bool
    {
        $text = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

        return match (true) {
            in_array($text, ['oui', 'o', 'yes', '1', 'true'], true) => true,
            in_array($text, ['non', 'n', 'no', '0', 'false'], true) => false,
            default => null,
        };
    }
}
