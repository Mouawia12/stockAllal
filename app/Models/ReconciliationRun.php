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
            'from_day' => 'date',
            'to_day' => 'date',
        ];
    }

    /** La periode demandee, telle que la page la passe (?from= &to=) : null = automatique. */
    public function period(): array
    {
        return ['from' => $this->from_day?->toDateString(), 'to' => $this->to_day?->toDateString()];
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

    /** L'analyse d'avant, pour la meme paire et la meme periode. */
    public function previous(): ?self
    {
        return self::samePeriod(self::where('customer_id', $this->customer_id)->where('supplier_id', $this->supplier_id), $this->period())
            ->where('id', '<', $this->id)->orderByDesc('id')->first();
    }

    /** Les analyses de cette periode demandee (null = automatique). */
    public static function samePeriod($query, array $period)
    {
        foreach (['from' => 'from_day', 'to' => 'to_day'] as $key => $column) {
            $period[$key] === null ? $query->whereNull($column) : $query->whereDate($column, $period[$key]);
        }

        return $query;
    }

    /** Garde les KEEP dernieres analyses de la paire. */
    public static function prune(int $customerId, int $supplierId): void
    {
        $keep = self::where('customer_id', $customerId)->where('supplier_id', $supplierId)
            ->orderByDesc('id')->limit(self::KEEP)->pluck('id');
        self::where('customer_id', $customerId)->where('supplier_id', $supplierId)->whereNotIn('id', $keep)->delete();
    }
}
