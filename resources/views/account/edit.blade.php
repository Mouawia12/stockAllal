@extends('layouts.app')

@section('title', __('common.account').' — '.__('common.app_name'))

@push('head')
<style>
    .account { max-width: 460px; }
    .account h1 { font-size: 20px; margin: 0 0 16px; }
    .account label { display: block; font-size: 13px; color: var(--muted); margin: 14px 0 4px; }
    .account input[type=text], .account input[type=password] { width: 100%; }
    .account hr { border: 0; border-top: 1px solid var(--border); margin: 20px 0 6px; }
    .account button { margin-top: 20px; }
</style>
@endpush

@section('content')
<form class="card account" method="post" action="{{ route('account.update') }}">
    @csrf @method('put')
    <h1>{{ __('common.account') }}</h1>

    <label for="name">{{ __('users.display_name') }}</label>
    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="100" required>

    <label for="username">{{ __('users.login_username') }}</label>
    <input id="username" type="text" name="username" value="{{ old('username', $user->username) }}" maxlength="50" required dir="ltr" autocomplete="username">

    <hr>
    <label for="password">{{ __('users.new_password') }} <span class="small">{{ __('users.leave_empty') }}</span></label>
    <input id="password" type="password" name="password" minlength="8" dir="ltr" autocomplete="new-password">

    <label for="password_confirmation">{{ __('users.confirm_password') }}</label>
    <input id="password_confirmation" type="password" name="password_confirmation" dir="ltr" autocomplete="new-password">

    <hr>
    <label for="current_password">{{ __('users.current_password') }} <span class="small">{{ __('users.required_to_save') }}</span></label>
    <input id="current_password" type="password" name="current_password" required dir="ltr" autocomplete="current-password">

    <button class="primary" type="submit">{{ __('common.save') }}</button>
</form>
@endsection
