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
</style>
@endpush

@section('content')
<div class="top">
    <h1>{{ __('users.title') }}</h1>
    <a class="btn primary" href="{{ route('users.create') }}">+ {{ __('users.new') }}</a>
</div>

<div class="card">
    @php($perms = __('users.perms'))
    <table>
        <thead>
        <tr>
            <th>{{ __('users.name') }}</th>
            <th>{{ __('users.username') }}</th>
            <th>{{ __('users.role') }}</th>
            <th class="hide-sm">{{ __('users.created') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td>{{ $u->name }} @if ($u->is(auth()->user()))<span class="muted small">{{ __('users.you') }}</span>@endif</td>
                <td><span class="ltr">{{ $u->username }}</span></td>
                <td>
                    @if ($u->is_admin)
                        <span class="pill ok">{{ __('users.admin') }}</span>
                    @elseif (empty($u->permissions))
                        <span class="pill">{{ __('users.no_perms') }}</span>
                    @else
                        <span class="chips">
                            @foreach ($u->permissions as $p)
                                <span class="pill" title="{{ $perms[$p]['help'] ?? '' }}">{{ $perms[$p]['short'] ?? $p }}</span>
                            @endforeach
                        </span>
                    @endif
                </td>
                <td class="hide-sm small muted">{{ $u->created_at?->diffForHumans() }}</td>
                <td>
                    <div class="actions">
                        <a class="btn" href="{{ route('users.edit', $u) }}">{{ __('common.edit') }}</a>
                        @unless ($u->is(auth()->user()))
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
    <p class="muted small">{!! __('users.help') !!}</p>
</div>
@endsection
