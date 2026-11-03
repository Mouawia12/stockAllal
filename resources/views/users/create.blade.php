@extends('layouts.app')

@section('title', __('users.new').' — '.__('common.app_name'))

@push('head')
<style>
    .user-page { max-width: 900px; }
    .user-page h1 { font-size: 20px; margin: 0 0 14px; }
    .user-page .bar { display: flex; gap: 8px; margin-top: 16px; }
</style>
@endpush

@section('content')
<form class="user-page" method="post" action="{{ route('users.store') }}">
    @csrf
    <h1>{{ __('users.new') }}</h1>
    @include('users.form', ['user' => null])
    <div class="bar">
        <button class="primary" type="submit">{{ __('users.create') }}</button>
        <a class="btn" href="{{ route('users.index') }}">{{ __('common.back') }}</a>
    </div>
</form>
@endsection
