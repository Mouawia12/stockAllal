<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// is_main hors Fillable : il ne change que par BranchController::main (une seule branche principale)
#[Fillable(['name', 'hostname', 'token_hash', 'active', 'agent_version', 'last_seen_at', 'last_sync_at', 'db_file_mtime', 'items_count'])]
#[Hidden(['token_hash'])]
class Branch extends Model
{
    /** L'agent interroge l'API toutes les 10 s : sans nouvelle depuis 60 s, il est considere hors ligne. */
    public const ONLINE_SECONDS = 60;

    /**
     * Demandes que le serveur envoie a un agent => premiere version d'agent qui les comprend.
     * Les branches mettent leur agent a jour a la main, quand on le decide : une demande n'est jamais envoyee a un agent
     * plus ancien (avant 1.0.4, une demande "sync" est lue comme un code vide et revient en invalid_code).
     */
    public const AGENT_FEATURES = ['sync' => '1.0.4'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_main' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'db_file_mtime' => 'datetime',
            'items_count' => 'integer',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }

    /** L'agent de la branche comprend-il cette demande (AGENT_FEATURES) ? Version inconnue = non. */
    public function agentSupports(string $feature): bool
    {
        return $this->agent_version !== null
            && version_compare($this->agent_version, self::AGENT_FEATURES[$feature], '>=');
    }

    /** Branche de reference des prix, si elle est active. */
    public static function main(): ?self
    {
        return self::where('is_main', true)->where('active', true)->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
