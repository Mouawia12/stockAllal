<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une analyse "دمج الأصناف" par l'IA (App\Support\MergeScanner) : les branches analysees, l'avancement de ses lots,
 * et ses propositions. Un compte ne voit que les analyses dont il voit toutes les branches (visibleTo).
 */
#[Fillable(['user_id', 'provider', 'model', 'locale', 'status', 'branch_ids', 'units', 'batches'])]
class MergeScan extends Model
{
    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batchRows(): HasMany
    {
        return $this->hasMany(MergeScanBatch::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(MergeSuggestion::class);
    }

    public function running(): bool
    {
        return $this->status === 'running';
    }

    /** Toutes ses branches font partie de celles que le compte voit (le developpeur les voit toutes). */
    public function visibleTo(User $user): bool
    {
        $ids = $user->stockBranchIds();

        return $ids === null || array_diff($this->branch_ids, $ids) === [];
    }

    /** 0 a 100 : les lots traites, reussis ou non. */
    public function progress(): int
    {
        return $this->batches === 0 ? 100 : (int) floor(100 * ($this->batches_done + $this->batches_failed) / $this->batches);
    }
}
