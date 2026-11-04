<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'code_raw', 'code_key', 'designation', 'stock', 'price', 'price_wholesale', 'price_buy', 'stock_alert', 'stock_max', 'family', 'unit', 'seen_at', 'updated_at'])]
class BranchStock extends Model
{
    /**
     * Prix lus dans la liste des articles de Gescomm (colonne de GC003 => titre dans Gescomm) :
     * price = PUGROS "Pu Detail" (التجزئة), price_wholesale = PRIXVNFACT "Prix Vente Gros" (الجملة),
     * price_buy = PRIX "Pu Achat" (الشراء) : jamais montre aux employes.
     */
    public const PRICES = ['price', 'price_wholesale', 'price_buy'];

    /**
     * Fiche de l'article dans Gescomm (GC003), envoyee a partir de l'agent 1.0.7 (null avant = inconnu) :
     * stock_alert = STOCKALERT, stock_max = STOCKMAX (couleurs de App\Support\StockState), family = CODEFAM, unit = UNITMESURE.
     */
    public const LEVELS = ['stock_alert', 'stock_max'];

    public const DETAILS = ['family', 'unit'];

    protected $table = 'branch_stock';

    // seen_at / updated_at sont ecrits explicitement par les synchros (upsert), seulement quand la ligne change :
    // updated_at = derniere valeur differente recue ; une valeur inchangee est confirmee par branches.last_sync_at

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'stock' => 'float',
            'price' => 'float',
            'price_wholesale' => 'float',
            'price_buy' => 'float',
            'stock_alert' => 'float',
            'stock_max' => 'float',
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
