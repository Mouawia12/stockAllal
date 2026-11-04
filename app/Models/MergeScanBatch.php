<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un envoi a l'IA d'une analyse : des groupes candidats a verifier (verify) ou des articles sans paire a comparer (discover). */
#[Fillable(['merge_scan_id', 'kind', 'clusters', 'status', 'attempts', 'error'])]
class MergeScanBatch extends Model
{
    /** Essais d'un lot avant de le compter "echoue" et de passer au suivant. */
    public const MAX_ATTEMPTS = 3;

    protected function casts(): array
    {
        return ['clusters' => 'array'];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(MergeScan::class, 'merge_scan_id');
    }
}
