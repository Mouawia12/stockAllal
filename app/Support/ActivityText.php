<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Une ligne du journal (activity_log) en mots : "عدّل حساب karim" + ses changements, "الدور: مستخدم ← مدير الفرع".
 * Les identifiants deviennent des noms (branches, roles, permissions) ; jamais le mot de passe ni une cle d'API, seulement qu'ils ont change.
 */
final class ActivityText
{
    /** @param  Collection<int, string>  $branchNames  id => nom, branches supprimees comprises si connues */
    public function __construct(private Collection $branchNames) {}

    /** @return array{title: string, changes: list<array{label: string, before: ?string, after: ?string}>} */
    public function describe(ActivityLog $log): array
    {
        $data = $log->data ?? [];

        return [
            // "user.created" -> activity.actions.user_created : un point dans une cle de traduction serait un niveau de plus
            'title' => __('activity.actions.'.str_replace('.', '_', $log->action), ['subject' => $log->action === 'install_code.created'
                ? (($data['for'] ?? null) === 'reinstall' ? __('activity.for_reinstall', ['name' => $this->subject($log)]) : __('activity.for_new'))
                : $this->subject($log)]),
            'changes' => match ($log->action) {
                'user.updated', 'branch.updated', 'ai.updated' => $this->fields($data),
                // nouveau compte : son role et sa branche
                'user.created' => [
                    $this->change('role', null, $this->role($data['state']['role'] ?? null)),
                    $this->change('branch_id', null, $this->names([$data['state']['branch_id'] ?? null])),
                ],
                'branch.access' => [$this->change('visible', $this->names($data['visible'][0] ?? []), $this->names($data['visible'][1] ?? []))],
                'branch.main' => [$this->change('is_main', $this->yesNo($data['is_main'][0] ?? null), $this->yesNo($data['is_main'][1] ?? null))],
                default => [],
            },
        ];
    }

    private function subject(ActivityLog $log): string
    {
        $data = $log->data ?? [];

        return match ($log->subject_type) {
            'user' => (string) ($data['state']['username'] ?? $data['username'][1] ?? User::find($log->subject_id)?->username ?? '#'.$log->subject_id),
            // renommee : name = [avant, apres] (branch.updated) ; supprimee : name = son nom (branch.deleted)
            'branch' => (string) ((is_array($data['name'] ?? null) ? $data['name'][1] : ($data['name'] ?? null))
                ?? $this->branchNames[$log->subject_id] ?? '#'.$log->subject_id),
            'aiprovider' => AiProviders::PROVIDERS[AiProvider::find($log->subject_id)?->provider]['name'] ?? '#'.$log->subject_id,
            default => '',
        };
    }

    /** @return list<array{label: string, before: ?string, after: ?string}> */
    private function fields(array $data): array
    {
        $out = [];
        foreach ($data as $field => [$before, $after]) {
            $out[] = match ($field) {
                'role' => $this->change($field, $this->role($before), $this->role($after)),
                'branch_id' => $this->change($field, $this->names([$before]), $this->names([$after])),
                'branches' => $this->change($field, $this->names($before ?? []), $this->names($after ?? [])),
                'active', 'enabled' => $this->change($field, $this->yesNo($before), $this->yesNo($after)),
                'permissions' => $this->change($field, $this->perms($before ?? []), $this->perms($after ?? [])),
                // jamais la valeur : seulement qu'elle a change
                'password', 'api_key' => ['label' => __("activity.fields.{$field}"), 'before' => null, 'after' => __('activity.changed')],
                default => $this->change($field, $before === null ? null : (string) $before, $after === null ? null : (string) $after),
            };
        }

        return $out;
    }

    private function change(string $field, ?string $before, ?string $after): array
    {
        return ['label' => __("activity.fields.{$field}"), 'before' => $before, 'after' => $after];
    }

    private function role(?string $role): ?string
    {
        return $role === null ? null : (string) __("users.roles.{$role}.title");
    }

    private function yesNo(mixed $value): ?string
    {
        return $value === null ? null : (string) __($value ? 'activity.yes' : 'activity.no');
    }

    private function names(array $ids): string
    {
        $names = collect($ids)->filter(fn ($id) => $id !== null)->map(fn ($id) => $this->branchNames[$id] ?? '#'.$id);

        return $names->isEmpty() ? (string) __('activity.none') : $names->implode(__('stock.js.list_separator'));
    }

    private function perms(array $perms): string
    {
        $labels = __('users.perms');

        return $perms === [] ? (string) __('activity.none')
            : collect($perms)->map(fn ($p) => $labels[$p]['short'] ?? $p)->implode(__('stock.js.list_separator'));
    }
}
