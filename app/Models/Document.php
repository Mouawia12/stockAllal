<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de vente (kind sale, GC010) ou d'achat (purchase, GC007). Venu de Gescomm (origin gescomm) : lecture seule,
 * ecrit par App\Support\DatasetSync. Un retour n'est pas un type de bon : c'est une ligne de quantite negative.
 */
class Document extends Model
{
    public const KINDS = ['sale', 'purchase'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'number' => 'integer',
            'amount' => 'float',
            'discount' => 'float',
            'gross' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }
}
