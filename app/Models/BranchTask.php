<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ou en est chaque tache de l'agent dans une branche (ventes, clients...) : dernier succes, derniere erreur.
 * La page des branches le montre : une tache qui echoue ne bloque pas les autres, elle doit se voir.
 */
#[Fillable(['branch_id', 'task', 'last_ok_at', 'last_error_at', 'last_error'])]
class BranchTask extends Model
{
    protected function casts(): array
    {
        return [
            'last_ok_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function ok(Branch $branch, string $task): void
    {
        self::updateOrCreate(['branch_id' => $branch->id, 'task' => $task], ['last_ok_at' => now()]);
    }

    public static function failed(Branch $branch, string $task, string $error): void
    {
        self::updateOrCreate(['branch_id' => $branch->id, 'task' => $task], ['last_error_at' => now(), 'last_error' => mb_substr($error, 0, 255)]);
    }

    /** En erreur : la derniere erreur est plus recente que le dernier succes. */
    public function failing(): bool
    {
        return $this->last_error_at !== null && ($this->last_ok_at === null || $this->last_error_at->gt($this->last_ok_at));
    }
}
