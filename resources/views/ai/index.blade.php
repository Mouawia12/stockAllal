@extends('layouts.app')

{{-- Lien avec Claude, ChatGPT et Gemini (AiSettingsController) : une carte par fournisseur, la cle jamais renvoyee au navigateur. --}}
@section('title', __('ai.title').' — '.__('common.app_name'))

@push('head')
<style>
    .ai-top h1 { font-size: 20px; margin: 0 0 6px; }
    .ai-top p { margin: 0 0 4px; max-width: 75ch; }
    .ai-top { margin-bottom: 16px; }
    .ai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; align-items: start; }
    .ai-card { display: flex; flex-direction: column; gap: 12px; overflow: visible; }
    .ai-card.is-on { border-color: var(--accent); box-shadow: inset 0 3px 0 var(--accent); }
    .ai-head { display: flex; align-items: flex-start; gap: 10px; }
    .ai-head h2 { font-size: 18px; margin: 0; line-height: 1.3; }
    .ai-head .grow { flex: 1; }
    .ai-card form.main label { display: block; font-size: 13px; color: var(--muted); margin: 0 0 4px; }
    .ai-card form.main .field { margin-bottom: 12px; }
    .ai-card input[type=password], .ai-card input[type=text], .ai-card select { width: 100%; }
    .ai-card select { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; }
    .ai-card .help { margin: 4px 0 0; }
    .ai-card label.check { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 15px; margin: 0 0 14px; cursor: pointer; }
    .ai-state { font-size: 13px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 10px; display: grid; gap: 4px; }
    .ai-state .detail { font-size: 12px; overflow-wrap: anywhere; }
    .ai-state .bad { color: var(--bad); }
    .ai-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .ai-actions .end { margin-inline-start: auto; }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', '1…'.count($providers)], 'label' => __('ai.api_key')])
@endpush

@section('content')
<div class="ai-top">
    <h1>{{ __('ai.title') }}</h1>
    <p>{{ __('ai.intro') }}</p>
    <p class="muted small">{{ __('ai.not_subscription') }}</p>
</div>

<div class="ai-grid">
@foreach ($providers as $id => $p)
    @php
        $row = $p['row'];
        $hasKey = $row->api_key !== null;
        $unreadable = ! $hasKey && $row->hasStoredKey();
        [$pill, $state] = match (true) {
            $unreadable => ['bad', __('ai.state.unreadable')],
            ! $hasKey => ['', __('ai.state.not_set')],
            $row->check_error !== null => ['bad', __('ai.state.error')],
            ! $row->enabled => ['', __('ai.state.off')],
            default => ['ok', __('ai.state.on')],
        };
        $models = $row->models ?? [];
    @endphp
    <section @class(['card', 'ai-card', 'is-on' => $hasKey && $row->enabled && $row->check_error === null]) id="ai-{{ $id }}" aria-labelledby="ai-{{ $id }}-name">
        <div class="ai-head">
            <div class="grow">
                <h2 id="ai-{{ $id }}-name">{{ $p['name'] }}</h2>
                <span class="muted small">{{ $p['company'] }}</span>
            </div>
            <span class="pill {{ $pill }}">{{ $state }}</span>
        </div>

        <form class="main" method="post" action="{{ route('ai.update', $id) }}">
            @csrf @method('put')
            <div class="field">
                <label for="key-{{ $id }}">{{ __('ai.api_key') }}</label>
                <input id="key-{{ $id }}" type="password" name="api_key" dir="ltr" maxlength="300" autocomplete="off" spellcheck="false"
                       data-key="Alt+{{ $loop->iteration }}"
                       placeholder="{{ $hasKey ? '•••• '.$row->keyEnd() : $p['placeholder'] }}">
                @if ($hasKey || $unreadable)
                    <p class="help muted small">{{ $hasKey ? __('ai.key_keep') : __('ai.key_unreadable', ['name' => $p['name']]) }}</p>
                @endif
                <p class="help small"><a href="{{ $p['keys_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('ai.where_key', ['company' => $p['company']]) }}</a></p>
            </div>
            <div class="field">
                <label for="model-{{ $id }}">{{ __('ai.model') }}</label>
                @if ($models)
                    <select id="model-{{ $id }}" name="model" dir="ltr">
                        @if ($row->model && ! in_array($row->model, $models, true))
                            <option value="{{ $row->model }}" selected>{{ $row->model }}</option>
                        @endif
                        @foreach ($models as $m)
                            <option value="{{ $m }}" @selected($m === $row->model)>{{ $m }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="model-{{ $id }}" type="text" name="model" dir="ltr" maxlength="100" value="{{ $row->model }}" spellcheck="false" placeholder="{{ __('ai.model_auto') }}">
                @endif
            </div>
            <label class="check"><input type="checkbox" name="enabled" value="1" @checked($row->enabled)>{{ __('ai.enabled') }}</label>
            {{-- tester et supprimer ont leurs propres formulaires (plus bas) : leurs boutons restent sur la meme ligne avec form="..." --}}
            <div class="ai-actions">
                <button class="primary" type="submit">{{ $hasKey ? __('common.save') : __('ai.connect') }}</button>
                @if ($hasKey)
                    <button type="submit" form="test-{{ $id }}">{{ __('ai.test') }}</button>
                @endif
                @if ($hasKey || $unreadable)
                    <button class="link danger end" type="submit" form="remove-{{ $id }}">{{ __('ai.remove_key') }}</button>
                @endif
            </div>
        </form>
        @if ($hasKey)
            <form id="test-{{ $id }}" method="post" action="{{ route('ai.test', $id) }}" hidden>@csrf</form>
        @endif
        @if ($hasKey || $unreadable)
            <form id="remove-{{ $id }}" method="post" action="{{ route('ai.destroy', $id) }}" hidden
                  onsubmit="return confirm(@js(__('ai.confirm_remove', ['name' => $p['name']])))">@csrf @method('delete')</form>
        @endif

        @if ($row->checked_at)
            <div class="ai-state">
                <span title="{{ $row->checked_at->format('d/m/Y H:i') }}">{{ __('ai.checked', ['time' => $row->checked_at->diffForHumans()]) }}</span>
                @if (! $row->check_error && $models)
                    <span>{{ trans_choice('ai.models_count', count($models), ['count' => count($models)]) }}</span>
                @endif
                @if ($row->check_error)
                    <span class="bad">{{ __("ai.errors.{$row->check_error}", ['name' => $p['name']]) }}</span>
                    @if ($row->check_detail)<span class="detail ltr">{{ $row->check_detail }}</span>@endif
                @endif
            </div>
        @endif
    </section>
@endforeach
</div>
@endsection
