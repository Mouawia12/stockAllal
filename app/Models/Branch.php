<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'hostname', 'token_hash', 'active', 'agent_version', 'last_seen_at', 'last_sync_at', 'db_file_mtime', 'items_count'])]
#[Hidden(['token_hash'])]
class Branch extends Model
{
    /** L'agent interroge l'API toutes les 10 s : sans nouvelle depuis 60 s, il est considere hors ligne. */
    public const ONLINE_SECONDS = 60;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
