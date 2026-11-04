@extends('layouts.app')

@section('title', __('users.edit_title', ['username' => $user->username]).' — '.__('common.app_name'))

@push('head')
<style>
    .user-page { max-width: 900px; }
    .user-page h1 { font-size: 20px; margin: 0 0 14px; }
    .user-page .bar { display: flex; gap: 8px; margin-top: 16px; }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', 'S'], 'run' => 'Alt+S', 'label' => __('common.save')])
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
@endpush

@section('content')
<form class="user-page" method="post" action="{{ route('users.update', $user) }}">
    @csrf @method('put')
    <h1>{{ __('users.edit_heading') }} <span class="ltr">{{ $user->username }}</span></h1>
    @include('users.form', ['user' => $user])
    <div class="bar">
        <button class="primary" type="submit" data-key="Alt+S">{{ __('common.save') }}</button>
        <a class="btn" href="{{ route('users.index') }}" data-key="Escape" data-back>{{ __('common.back') }}</a>
    </div>
</form>
@endsection
