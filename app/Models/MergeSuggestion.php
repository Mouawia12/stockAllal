<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une proposition de l'IA : des articles de branches differentes qui seraient le meme produit.
 * units = [[code_key, ...], ...] : un article par entree (plusieurs codes s'il est deja un groupe fusionne).
 */
#[Fillable(['merge_scan_id', 'units', 'confidence', 'source', 'name', 'reason', 'status', 'decided_by', 'decided_at'])]
class MergeSuggestion extends Model
{
    public const CONFIDENCES = ['high', 'medium', 'low'];

    protected function casts(): array
    {
        return [
            'units' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(MergeScan::class, 'merge_scan_id');
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_merge(...$this->units)));
    }
}
