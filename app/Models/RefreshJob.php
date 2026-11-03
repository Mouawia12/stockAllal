<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande envoyee a l'agent d'une branche, recuperee par GET /api/agent/pending.
 * type "code" : lire un produit (bouton "تحديث الآن") ; type "sync" : renvoyer tout le stock (bouton "مزامنة الآن").
 * Statut "waiting" : "مزامنة الآن" pour un agent qui ne connait pas "sync" (avant 1.0.4). La demande ne lui est pas
 * envoyee : sa prochaine synchro automatique (toutes les 5 min) y repond.
 */
#[Fillable(['batch', 'type', 'branch_id', 'code_key', 'status', 'requested_by', 'taken_at', 'answered_at', 'result'])]
class RefreshJob extends Model
{
    /** Au-dela, une branche qui n'a pas repondu est affichee "pas de reponse". */
    public const TIMEOUT_SECONDS = 30;

    /** Une synchro complete envoie tout le stock : on lui laisse plus de temps sur une ligne lente. */
    public const SYNC_TIMEOUT_SECONDS = 120;

    /** Agent avant 1.0.4 : synchro automatique toutes les 5 min, plus le temps de l'envoi. */
    public const WAIT_SYNC_SECONDS = 420;

    /** Une demande plus ancienne n'est plus envoyee a l'agent (il etait hors ligne). */
    public const PICKUP_MINUTES = 2;

    protected $attributes = [
        'type' => 'code',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'answered_at' => 'datetime',
            'result' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function displayStatus(): string
    {
        if ($this->status === 'waiting' && $this->branch->last_sync_at?->gte($this->created_at)) {
            return 'done';
        }
        if (in_array($this->status, ['pending', 'taken', 'waiting'], true)
            && $this->created_at->lt(now()->subSeconds($this->timeoutSeconds()))) {
            return 'timeout';
        }

        return $this->status;
    }

    public function timeoutSeconds(): int
    {
        return match (true) {
            $this->status === 'waiting' => self::WAIT_SYNC_SECONDS,
            $this->type === 'sync' => self::SYNC_TIMEOUT_SECONDS,
            default => self::TIMEOUT_SECONDS,
        };
    }
}
