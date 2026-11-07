<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une analyse de "المطابقة", gardee (App\Http\Controllers\ReconcileController) : le rapport entier, avec la conclusion
 * de l'IA, et ce qui a change depuis l'analyse precedente de la meme paire.
 */
class ReconciliationRun extends Model
{
    /** Analyses gardees par paire (les plus anciennes partent). */
    public const KEEP = 30;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'gap' => 'float',
            'problems' => 'integer',
            'synced' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** L'analyse d'avant, pour la meme paire. */
    public function previous(): ?self
    {
        return self::where('customer_id', $this->customer_id)->where('supplier_id', $this->supplier_id)
            ->where('id', '<', $this->id)->orderByDesc('id')->first();
    }

    /** Garde les KEEP dernieres analyses de la paire. */
    public static function prune(int $customerId, int $supplierId): void
    {
        $keep = self::where('customer_id', $customerId)->where('supplier_id', $supplierId)
            ->orderByDesc('id')->limit(self::KEEP)->pluck('id');
        self::where('customer_id', $customerId)->where('supplier_id', $supplierId)->whereNotIn('id', $keep)->delete();
    }
}
