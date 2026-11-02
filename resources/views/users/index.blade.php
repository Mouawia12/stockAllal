@extends('layouts.app')

@section('title', __('users.title').' — '.__('common.app_name'))

@push('head')
<style>
    .page-title { font-size: 20px; margin: 0 0 16px; }
    .new-user { margin-bottom: 16px; }
    .new-user h2 { font-size: 16px; margin: 0 0 12px; }
    .new-user .submit { margin-top: 14px; }
    .actions { display: flex; gap: 6px; justify-content: flex-end; }
    .actions .btn, .actions button { padding: 4px 12px; }
</style>
@endpush

@section('content')
<h1 class="page-title">{{ __('users.title') }}</h1>

<form class="card new-user" method="post" action="{{ route('users.store') }}">
    @csrf
    <h2>{{ __('users.new') }}</h2>
    @include('users.form', ['user' => null])
    <div class="submit"><button class="primary" type="submit">{{ __('users.create') }}</button></div>
</form>

<div class="card">
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
                    @else
                        <span class="pill">{{ __('users.worker') }}</span>
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
