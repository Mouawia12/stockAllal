<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// branch_id = branche creee par ce code ; for_branch_id = code de reinstallation de l'agent de cette branche
#[Fillable(['code_hash', 'expires_at', 'used_at', 'branch_id', 'for_branch_id', 'created_by'])]
class InstallCode extends Model
{
    // sans 0/O ni 1/I/L : le code est recopie a la main sur le serveur de la branche
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Cree un code a usage unique valable 24 h et le renvoie en clair (format XXX-XXX-XXX).
     * $forBranchId : reinstallation de l'agent de cette branche (meme branche, nouveau token) au lieu d'une nouvelle.
     */
    public static function generate(?int $userId, ?int $forBranchId = null): string
    {
        $raw = '';
        for ($i = 0; $i < 9; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        static::create([
            'code_hash' => self::hash($raw),
            'expires_at' => now()->addDay(),
            'created_by' => $userId,
            'for_branch_id' => $forBranchId,
        ]);

        return implode('-', str_split($raw, 3));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }
}
