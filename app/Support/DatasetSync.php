<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Synchro des jeux de AgentDatasets, en deux temps (agent/README.md) :
 * 1. digest : l'agent donne l'empreinte de chaque groupe ("nombre:somme des empreintes"), le serveur repond ceux qui
 *    different des siens. Rien n'a change = rien n'est envoye ; une coupure = les groupes pas encore arrives different
 *    encore au prochain passage, l'envoi reprend la ou il s'etait arrete.
 * 2. records : l'agent envoie ces groupes, complets. Le serveur remplace chaque groupe : il n'ecrit que les lignes dont
 *    l'empreinte a change et retire celles qui n'y sont plus. Un envoi rejoue ne change rien.
 */
final class DatasetSync
{
    /** Taille conseillee d'un envoi (enregistrements + lignes), et le maximum accepte (un jour entier ne se coupe pas). */
    public const PAGE_ROWS = 1000;

    public const MAX_ROWS = 10000;

    /**
     * Groupes que l'agent doit envoyer : ceux dont l'empreinte differe, et ceux que le serveur a encore mais plus l'agent
     * (envoyes vides, ils sont vides ici aussi). $from / $to (jeu date) : seulement les jours de cet intervalle.
     *
     * @param  array<string, string>  $groups
     * @return list<string>
     */
    public static function differing(Branch $branch, array $set, array $groups, ?string $from, ?string $to): array
    {
        $inRange = fn (string $group) => ($from === null || strcmp($group, $from) >= 0) && ($to === null || strcmp($group, $to) <= 0);

        $theirs = [];
        foreach ($groups as $group => $digest) {
            $group = (string) $group;
            if (AgentDatasets::validGroup($set, $group) && $inRange($group)) {
                $theirs[$group] = (string) $digest;
            }
        }

        $ours = self::scoped($branch, $set)
            ->when($from !== null, fn (Builder $q) => $q->where('sync_group', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('sync_group', '<=', $to))
            ->groupBy('sync_group')
            ->selectRaw('sync_group, COUNT(*) as n, SUM(sync_digest) as s')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->sync_group => $row->n.':'.preg_replace('/\.0+$/', '', (string) $row->s)])
            ->all();

        $send = [];
        foreach ($theirs as $group => $digest) {
            if (($ours[$group] ?? null) !== $digest) {
                $send[] = $group;
            }
        }
        foreach (array_keys($ours) as $group) {
            if (! array_key_exists($group, $theirs)) {
                $send[] = $group;
            }
        }
        rsort($send, SORT_STRING);

        return $send;
    }

    /**
     * Remplace ces groupes par ce que l'agent envoie.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return array{written: int, removed: int}
     */
    public static function store(Branch $branch, array $set, array $groups): array
    {
        $written = 0;
        $removed = 0;
        DB::transaction(function () use ($branch, $set, $groups, &$written, &$removed) {
            foreach ($groups as $group => $records) {
                [$w, $r] = self::replaceGroup($branch, $set, (string) $group, is_array($records) ? $records : []);
                $written += $w;
                $removed += $r;
            }
        });

        return ['written' => $written, 'removed' => $removed];
    }

    /** Lignes recues dans un envoi : enregistrements + lignes des documents (borne MAX_ROWS). */
    public static function rowCount(array $groups): int
    {
        $count = 0;
        foreach ($groups as $records) {
            foreach (is_array($records) ? $records : [] as $record) {
                $count += 1 + (is_array($record['lines'] ?? null) ? count($record['lines']) : 0);
            }
        }

        return $count;
    }

    /** @return array{0: int, 1: int} ecrits, retires */
    private static function replaceGroup(Branch $branch, array $set, string $group, array $records): array
    {
        $incoming = [];
        foreach ($records as $record) {
            $key = is_array($record) ? self::key($record['key'] ?? null) : null;
            $digest = is_array($record) ? self::digest($record['digest'] ?? null) : null;
            if ($key !== null && $digest !== null) {
                $incoming[$key] = [$record, $digest];
            }
        }

        $here = self::scoped($branch, $set)->where('sync_group', $group)->get(['id', 'gescomm_key', 'sync_digest'])->keyBy('gescomm_key');
        // un document dont la date a change dans Gescomm arrive dans un autre jour : on le deplace
        $moved = array_values(array_diff(array_map('strval', array_keys($incoming)), $here->keys()->map(fn ($k) => (string) $k)->all()));
        $elsewhere = $moved === [] ? collect() : self::scoped($branch, $set)->whereIn('gescomm_key', $moved)->get(['id', 'gescomm_key'])->keyBy('gescomm_key');

        $now = now();
        $written = 0;
        foreach ($incoming as $key => [$record, $digest]) {
            $key = (string) $key;
            $old = $here->get($key);
            if ($old !== null && (int) $old->sync_digest === $digest) {
                continue;
            }
            $old ??= $elsewhere->get($key);
            $row = AgentDatasets::row($set, $record, $group) + [
                'gescomm_key' => $key,
                'sync_group' => $group,
                'sync_digest' => $digest,
                'updated_at' => $now,
            ];
            if ($old !== null) {
                DB::table($set['table'])->where('id', $old->id)->update($row);
                $id = $old->id;
            } else {
                $id = DB::table($set['table'])->insertGetId($row + ['branch_id' => $branch->id, 'origin' => 'gescomm', 'created_at' => $now] + $set['scope']);
            }
            if ($set['lines']) {
                DB::table('document_lines')->where('document_id', $id)->delete();
                $lines = array_map(fn (array $line) => $line + [
                    'document_id' => $id, 'branch_id' => $branch->id, 'kind' => $set['scope']['kind'], 'date' => $row['date'],
                ], AgentDatasets::lines($record));
                foreach (array_chunk($lines, 500) as $chunk) {
                    DB::table('document_lines')->insert($chunk);
                }
            }
            $written++;
        }

        $gone = $here->reject(fn (object $row, $key) => array_key_exists((string) $key, $incoming))->pluck('id');
        foreach ($gone->chunk(500) as $ids) {
            if ($set['lines']) {
                DB::table('document_lines')->whereIn('document_id', $ids)->delete();
            }
            DB::table($set['table'])->whereIn('id', $ids)->delete();
        }

        return [$written, $gone->count()];
    }

    /** Les lignes de ce jeu dans cette branche, venues de Gescomm (jamais celles saisies dans le web). */
    private static function scoped(Branch $branch, array $set): Builder
    {
        return DB::table($set['table'])->where('branch_id', $branch->id)->where($set['scope'])
            ->where('origin', 'gescomm')->whereNotNull('sync_group');
    }

    private static function key(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $key = rtrim((string) $value);

        return $key === '' || mb_strlen($key) > 40 ? null : $key;
    }

    private static function digest(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 && $value <= AgentDatasets::DIGEST_MAX ? $value : null;
    }
}
