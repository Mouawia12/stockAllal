@extends('layouts.app')

@section('title', __('users.title').' — '.__('common.app_name'))

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    a.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
    .chips { display: flex; flex-wrap: wrap; gap: 4px; }
    .actions { display: flex; gap: 6px; justify-content: flex-end; }
    .actions .btn, .actions button { padding: 4px 12px; }
    .group { margin-bottom: 14px; }
    .group h2 { font-size: 15px; margin: 0 0 8px; }
    .group.warn { border-color: var(--warn); }
    tr.off td { opacity: .6; }
</style>
@endpush

@section('content')
<div class="top">
    <h1>{{ __('users.title') }}</h1>
    <a class="btn primary" href="{{ route('users.create') }}">+ {{ __('users.new') }}</a>
</div>

{{-- arbre : la direction, puis chaque branche (son directeur d'abord), puis les comptes sans branche (UserController::grouped) --}}
@php($perms = __('users.perms'))
@php($roleTexts = __('users.roles'))
@foreach ($groups as $group)
<div class="card group {{ $group['warn'] ? 'warn' : '' }}">
    <h2>{{ $group['title'] }}</h2>
    @if ($group['warn'])
        <p class="muted small">{{ __('users.no_branch_hint') }}</p>
    @endif
    <table>
        <thead>
        <tr>
            <th>{{ __('users.name') }}</th>
            <th>{{ __('users.username') }}</th>
            <th>{{ __('users.role') }}</th>
            <th class="hide-sm">{{ __('users.last_login') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($group['users'] as $u)
            @php($self = $u->is($me))
            <tr class="{{ $u->active ? '' : 'off' }}">
                <td>{{ $u->name }} @if ($self)<span class="muted small">{{ __('users.you') }}</span>@endif</td>
                <td><span class="ltr">{{ $u->username }}</span></td>
                <td>
                    <span class="chips">
                        <span class="pill {{ in_array($u->role, ['developer', 'owner'], true) ? 'ok' : '' }}">{{ $roleTexts[$u->role]['title'] }}</span>
                        @unless ($u->active)
                            <span class="pill bad">{{ __('users.inactive') }}</span>
                        @endunless
                        @if (! $u->isDeveloper() && count($u->permissions ?? []) === count(App\Support\Permissions::all()))
                            <span class="pill">{{ __('users.all_perms') }}</span>
                        @elseif (! $u->isDeveloper())
                            @forelse ($u->permissions ?? [] as $p)
                                <span class="pill" title="{{ $perms[$p]['help'] ?? '' }}">{{ $perms[$p]['short'] ?? $p }}</span>
                            @empty
                                <span class="pill">{{ __('users.no_perms') }}</span>
                            @endforelse
                        @endif
                    </span>
                </td>
                <td class="hide-sm small muted">{{ $u->last_login_at?->diffForHumans() ?? __('users.never') }}</td>
                <td>
                    <div class="actions">
                        {{-- son propre compte : "حسابي" (on ne change pas son role ni ses droits ici) --}}
                        <a class="btn" href="{{ $self ? route('account.edit') : route('users.edit', $u) }}">{{ __('common.edit') }}</a>
                        @unless ($self)
                            {{-- @js : un ' dans le nom d'utilisateur ne casse pas (ni n'injecte) le JavaScript de l'attribut --}}
                            <form method="post" action="{{ route('users.destroy', $u) }}"
                                  onsubmit="return confirm(@js(__('users.confirm_delete', ['username' => $u->username])))">
                                @csrf @method('delete')
                                <button class="danger" type="submit">{{ __('common.delete') }}</button>
                            </form>
                        @endunless
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endforeach
<p class="muted small">{!! __('users.help') !!}</p>
@endsection
