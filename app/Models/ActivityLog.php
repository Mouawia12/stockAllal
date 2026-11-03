<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du journal : qui a fait quoi et quand. Ecrit par App\Support\Activity::log(), jamais modifie. */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'data', 'ip', 'created_at'])]
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
