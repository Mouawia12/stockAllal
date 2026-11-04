<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Deux codes qu'un humain a declares differents ("ليسا نفس الصنف") : aucune analyse ne les repropose ensemble. */
#[Fillable(['pair', 'user_id'])]
class MergeDismissal extends Model
{
    public static function pair(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? "{$a}|{$b}" : "{$b}|{$a}";
    }

    /**
     * Toutes les paires de ces codes, enregistrees une fois.
     *
     * @param  list<string>  $codes
     */
    public static function remember(array $codes, ?int $userId): void
    {
        $codes = array_values(array_unique($codes));
        $rows = [];
        foreach ($codes as $i => $a) {
            foreach (array_slice($codes, $i + 1) as $b) {
                $rows[] = ['pair' => self::pair($a, $b), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($rows) {
            self::insertOrIgnore($rows);
        }
    }
}
