@extends('layouts.app')

@section('title', 'التطبيقات — مخزون الفروع')

@push('head')
<style>
    main { max-width: none; padding: 0; }
    .home { min-height: calc(100vh - 57px); padding: 56px 16px 80px;
        background:
            radial-gradient(900px 420px at 85% -10%, var(--accent-soft), transparent 70%),
            radial-gradient(700px 380px at 0% 110%, var(--accent-soft), transparent 70%); }
    .home .in { max-width: 760px; margin: 0 auto; }
    .hello { text-align: center; margin: 0 0 6px; font-size: 22px; font-weight: 600; }
    .hello-sub { text-align: center; margin: 0 0 40px; color: var(--muted); }
    .apps { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 28px 12px; justify-items: center; }
    .app { display: flex; flex-direction: column; align-items: center; gap: 9px; width: 112px; color: var(--text); text-decoration: none; border-radius: 16px; padding: 6px 0; }
    .app .tile { width: 80px; height: 80px; border-radius: 20px; background: var(--tile); box-shadow: var(--shadow); border: 1px solid var(--border);
        display: grid; place-items: center; transition: transform .15s ease, box-shadow .15s ease; }
    .app .tile svg { width: 50px; height: 50px; }
    .app .label { font-size: 14px; font-weight: 600; }
    a.app:hover .tile, a.app:focus-visible .tile { transform: translateY(-3px); box-shadow: 0 10px 26px rgba(0, 0, 0, .14); }
    a.app:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    .app.soon { cursor: default; }
    .app.soon .tile svg { filter: saturate(.55); opacity: .7; }
    .app.soon .label { color: var(--muted); font-weight: 500; }
    .soon-pill { font-size: 11px; line-height: 1; padding: 4px 9px; border-radius: 99px; background: var(--accent-soft); color: var(--accent); margin-top: -3px; }
    @media (max-width: 480px) {
        .home { padding-top: 32px; }
        .apps { grid-template-columns: repeat(3, 1fr); gap: 22px 4px; }
        .app { width: 96px; }
        .app .tile { width: 68px; height: 68px; border-radius: 17px; }
        .app .tile svg { width: 42px; height: 42px; }
    }
</style>
@endpush

@section('content')
<div class="home">
    <div class="in">
        <p class="hello">مرحباً {{ auth()->user()->name }}</p>
        <p class="hello-sub">اختر التطبيق</p>
        <div class="apps">
            @foreach ($apps as $key => $app)
                @if ($app['route'])
                    <a class="app" href="{{ route($app['route']) }}">
                        <span class="tile">@include('partials.app-icon', ['name' => $key])</span>
                        <span class="label">{{ $app['name'] }}</span>
                    </a>
                @else
                    <div class="app soon" aria-disabled="true" title="{{ $app['name'] }}: قيد التطوير">
                        <span class="tile">@include('partials.app-icon', ['name' => $key])</span>
                        <span class="label">{{ $app['name'] }}</span>
                        <span class="soon-pill">قريباً</span>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection
