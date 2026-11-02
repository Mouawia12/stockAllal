@extends('layouts.app')

@section('title', __('users.edit_title', ['username' => $user->username]).' — '.__('common.app_name'))

@push('head')
<style>
    .edit-user { max-width: 760px; }
    .edit-user h1 { font-size: 20px; margin: 0 0 14px; }
    .edit-user .bar { display: flex; gap: 8px; margin-top: 16px; }
</style>
@endpush

@section('content')
<form class="card edit-user" method="post" action="{{ route('users.update', $user) }}">
    @csrf @method('put')
    <h1>{{ __('users.edit_heading') }} <span class="ltr">{{ $user->username }}</span></h1>
    @include('users.form', ['user' => $user])
    @if ($user->is(auth()->user()))
        <p class="muted small">{{ __('users.own_account') }}</p>
    @endif
    <div class="bar">
        <button class="primary" type="submit">{{ __('common.save') }}</button>
        <a class="btn" href="{{ route('users.index') }}">{{ __('common.back') }}</a>
    </div>
</form>
@endsection
