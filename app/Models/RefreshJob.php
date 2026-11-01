<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch', 'branch_id', 'code_key', 'status', 'requested_by', 'taken_at', 'answered_at', 'result'])]
class RefreshJob extends Model
{
    /** Au-dela, une branche qui n'a pas repondu est affichee "pas de reponse". */
    public const TIMEOUT_SECONDS = 30;

    /** Une demande plus ancienne n'est plus envoyee a l'agent (il etait hors ligne). */
    public const PICKUP_MINUTES = 2;

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
        if (in_array($this->status, ['pending', 'taken'], true)
            && $this->created_at->lt(now()->subSeconds(self::TIMEOUT_SECONDS))) {
            return 'timeout';
        }

        return $this->status;
    }
}
