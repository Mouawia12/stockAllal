<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/** Journal des actions qui changent les comptes et les branches (table activity_log). */
final class Activity
{
    /**
     * @param  Model|null  $subject  le compte, la branche... concerne(e)
     * @param  array<string, mixed>  $data  ce qui a change, ex. ['role' => ['user', 'branch_manager']]
     */
    public static function log(string $action, ?Model $subject = null, array $data = []): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? strtolower(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Champs qui ont change entre deux etats : {champ: [avant, apres]}.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) != $value) {
                $changes[$field] = [$before[$field] ?? null, $value];
            }
        }

        return $changes;
    }
}
