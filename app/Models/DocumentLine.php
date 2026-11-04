<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'un document ; branch_id, kind et date copies de l'en-tete pour la page d'un article. */
class DocumentLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qty' => 'float',
            'price' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'cost' => 'float',
            'vat' => 'float',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
