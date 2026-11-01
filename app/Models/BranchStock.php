<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'code_raw', 'code_key', 'designation', 'stock', 'price', 'seen_at', 'updated_at'])]
class BranchStock extends Model
{
    protected $table = 'branch_stock';

    // seen_at / updated_at sont ecrits explicitement par les synchros (upsert), seulement quand la ligne change :
    // updated_at = derniere valeur differente recue ; une valeur inchangee est confirmee par branches.last_sync_at

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'stock' => 'float',
            'price' => 'float',
            'seen_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Cle de regroupement entre branches : espaces de bord retires + majuscules, rien d'autre. */
    public static function keyFor(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
