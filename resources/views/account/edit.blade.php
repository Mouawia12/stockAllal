@extends('layouts.app')

@section('title', __('common.account').' — '.__('common.app_name'))

@push('head')
<style>
    /* compte a cote de "صلاحياتك" sur grand ecran, l'un sous l'autre sur telephone */
    .account-page { display: grid; grid-template-columns: minmax(0, 460px) minmax(0, 340px); gap: 16px; align-items: start; }
    @media (max-width: 860px) { .account-page { grid-template-columns: minmax(0, 460px); } }
    .account h1 { font-size: 20px; margin: 0 0 16px; }
    .account label { display: block; font-size: 13px; color: var(--muted); margin: 14px 0 4px; }
    .account input[type=text], .account input[type=password] { width: 100%; }
    .account hr { border: 0; border-top: 1px solid var(--border); margin: 20px 0 6px; }
    .account button { margin-top: 20px; }
    .my-perms h2 { font-size: 16px; margin: 0 0 10px; }
    .my-perms ul { list-style: none; margin: 0 0 8px; padding: 0; display: grid; gap: 6px; }
    .my-perms li { display: flex; gap: 8px; align-items: baseline; }
    .my-perms .yes { color: var(--ok); }
    .my-perms .no { color: var(--muted); }
    .my-perms li.off span:last-child { color: var(--muted); text-decoration: line-through; }
</style>
@endpush

@section('content')
<div class="account-page">
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

{{-- lecture seule : ce que ce compte peut faire ; seul un administrateur le change (page des comptes) --}}
<section class="card my-perms">
    <h2>{{ __('users.your_perms') }}</h2>
    @if ($user->is_admin)
        <p class="pill ok">{{ __('users.your_perms_admin') }}</p>
    @else
        @php($perms = __('users.perms'))
        <ul>
            <li><span class="yes">✓</span><span>{{ __('users.perms_always') }}</span></li>
            @foreach (App\Support\Permissions::all() as $p)
                @php($has = $user->hasPermission($p))
                <li class="{{ $has ? '' : 'off' }}"><span class="{{ $has ? 'yes' : 'no' }}">{{ $has ? '✓' : '✕' }}</span><span>{{ $perms[$p]['title'] }}</span></li>
            @endforeach
        </ul>
        <p class="muted small">{{ __('users.your_perms_help') }}</p>
    @endif
</section>
</div>
@endsection
