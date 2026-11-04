<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Versement d'un client (partner_kind customer, GC62) ou a un fournisseur (supplier, GC60). */
class Payment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'paid_on' => 'date',
            'amount' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
