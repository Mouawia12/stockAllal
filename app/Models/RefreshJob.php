<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande envoyee a l'agent d'une branche, recuperee par GET /api/agent/pending.
 * type "code" : lire un produit (bouton "تحديث الآن") ; type "sync" : renvoyer tout le stock (bouton "مزامنة الآن").
 */
#[Fillable(['batch', 'type', 'branch_id', 'code_key', 'status', 'requested_by', 'taken_at', 'answered_at', 'result'])]
class RefreshJob extends Model
{
    /** Au-dela, une branche qui n'a pas repondu est affichee "pas de reponse". */
    public const TIMEOUT_SECONDS = 30;

    /** Une synchro complete envoie tout le stock : on lui laisse plus de temps sur une ligne lente. */
    public const SYNC_TIMEOUT_SECONDS = 120;

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
        $timeout = $this->type === 'sync' ? self::SYNC_TIMEOUT_SECONDS : self::TIMEOUT_SECONDS;
        if (in_array($this->status, ['pending', 'taken'], true)
            && $this->created_at->lt(now()->subSeconds($timeout))) {
            return 'timeout';
        }

        return $this->status;
    }
}
