@extends('layouts.app')

{{-- Journal (ActivityController) : un fil par jour, facon "chatter" d'Odoo : qui, quand, quoi, et chaque changement avant -> apres. --}}
@section('title', __('activity.title').' — '.__('common.app_name'))

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    .filters { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; padding: 4px 12px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 14px; background: var(--card); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .day { font-size: 13px; color: var(--muted); font-weight: 600; margin: 18px 0 8px; }
    .feed { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
    .entry { display: grid; grid-template-columns: 34px 1fr; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); }
    .entry:last-child { border-bottom: 0; }
    .who { width: 34px; height: 34px; border-radius: 50%; background: var(--accent-soft); color: var(--accent); display: grid; place-items: center; font-weight: 700; }
    .entry-head { display: flex; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .entry-head b { font-weight: 600; }
    .entry-title { margin: 2px 0 0; }
    .changes { list-style: none; margin: 6px 0 0; padding: 0; display: grid; gap: 3px; font-size: 14px; }
    .changes .label { color: var(--muted); }
    .changes .before { color: var(--muted); text-decoration: line-through; text-decoration-color: var(--border); }
    .changes .arrow { color: var(--muted); margin: 0 4px; }
    .pager { display: flex; justify-content: space-between; margin-top: 14px; }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
</style>
@endpush

@section('content')
<div class="top">
    <h1>{{ __('activity.title') }}</h1>
    <nav class="filters" aria-label="{{ __('activity.filter') }}">
        @foreach (array_keys(App\Http\Controllers\ActivityController::TYPES) as $t)
            <a href="{{ route('activity.index', $t === 'all' ? [] : ['type' => $t]) }}" @class(['chip', 'on' => $type === $t]) @if ($type === $t) aria-current="true" @endif>{{ __("activity.types.{$t}") }}</a>
        @endforeach
    </nav>
</div>

<div class="card">
    @if ($entries->isEmpty())
        <p class="empty">{{ __('activity.empty') }}</p>
    @else
        @foreach ($entries->groupBy(fn ($e) => $e['log']->created_at->toDateString()) as $date => $day)
            @php($d = \Illuminate\Support\Carbon::parse($date))
            <h2 class="day">{{ $d->isToday() ? __('activity.today') : ($d->isYesterday() ? __('activity.yesterday') : $d->format('d/m/Y')) }}</h2>
            <ul class="feed">
                @foreach ($day as $e)
                    @php($actor = $e['log']->user?->name ?? __('activity.system'))
                    <li class="entry">
                        <span class="who" aria-hidden="true">{{ mb_substr($actor, 0, 1) }}</span>
                        <div>
                            <div class="entry-head">
                                <b>{{ $actor }}</b>
                                <time class="muted small" datetime="{{ $e['log']->created_at->toIso8601String() }}" title="{{ $e['log']->created_at->format('d/m/Y H:i') }}">{{ $e['log']->created_at->format('H:i') }}</time>
                            </div>
                            <p class="entry-title">{{ $e['title'] }}</p>
                            @if ($e['changes'])
                                <ul class="changes">
                                    @foreach ($e['changes'] as $c)
                                        <li>
                                            <span class="label">{{ $c['label'] }}:</span>
                                            @if ($c['before'] !== null)<span class="before">{{ $c['before'] }}</span><span class="arrow" aria-label="{{ __('activity.became') }}">{{ __('activity.arrow') }}</span>@endif
                                            <span>{{ $c['after'] ?? __('activity.none') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach
        @if ($logs->hasPages())
            <div class="pager">
                @if ($logs->previousPageUrl())<a class="btn" href="{{ $logs->previousPageUrl() }}">{{ __('activity.newer') }}</a>@else<span></span>@endif
                @if ($logs->nextPageUrl())<a class="btn" href="{{ $logs->nextPageUrl() }}">{{ __('activity.older') }}</a>@endif
            </div>
        @endif
    @endif
</div>
@endsection
