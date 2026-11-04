<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Client (kind customer, GC002) ou fournisseur (supplier, GC004) d'une branche. */
class Partner extends Model
{
    public const KINDS = ['customer', 'supplier'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'float',
            'credit_limit' => 'float',
            'blocked' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
