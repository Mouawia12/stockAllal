<?php

namespace App\Models;

use App\Models\Scopes\ExerciseScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de vente (kind sale, GC010) ou d'achat (purchase, GC007). Venu de Gescomm (origin gescomm) : lecture seule,
 * ecrit par App\Support\DatasetSync. Un retour n'est pas un type de bon : c'est une ligne de quantite negative.
 * Ceux de l'exercice regarde (ExerciseScope) ; un bon ouvert par son id s'ouvre quel que soit son exercice.
 */
#[ScopedBy([ExerciseScope::class])]
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
        return $this->hasMany(DocumentLine::class)->withoutGlobalScope(ExerciseScope::class)->orderBy('position');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** /sales/{document} : le lien d'un bon d'un exercice clos (rapprochement, fenetre rapide) l'ouvre aussi. */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutGlobalScope(ExerciseScope::class)->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
