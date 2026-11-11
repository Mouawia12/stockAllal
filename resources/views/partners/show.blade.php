@extends('layouts.app')

{{--
    Fiche d'un client ou d'un fournisseur (PartnerController) : qui il est, ce qu'il a achete sur la periode
    (bons, montant, retours, versements), trois boutons qui ouvrent le detail dans une fenetre par pages
    (partners/_records-modal), puis la nature de la relation sur 12 mois (partners/_insights).
    Le solde, comme Gescomm le montre (PartnerController::balance) : sous le nom, avec les montants (prices.*).
--}}
@php
    $P = __('partners');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $facts = array_filter([
        'phone' => $partner->phone,
        'mobile' => $partner->mobile,
        'address' => $partner->address,
        'wilaya' => $partner->wilaya,
        'contact' => $partner->contact,
        'activity' => $partner->activity,
        'type' => $partner->type,
        'nif' => $partner->nif,
        'nis' => $partner->nis,
        'rc' => $partner->rc,
        'ai' => $partner->ai,
        'credit_days' => $partner->credit_days ? trans_choice('documents.days', $partner->credit_days, ['count' => $partner->credit_days]) : null,
        'credit_limit' => $money && $partner->credit_limit ? $fmt($partner->credit_limit) : null,
        'opening_balance' => $money && $partner->opening_balance ? $fmt($partner->opening_balance) : null,
    ], fn ($v) => $v !== null && $v !== '');
    // la touche suit celles des periodes (Alt+1...4) : un chiffre reste le meme sur un clavier arabe ou AZERTY
    $opens = [
        'bons' => ['n' => $stats['bons'], 'key' => '5', 'sub' => $stats['last'] ? __('partners.open.last_bon', ['date' => $stats['last']]) : $P['open']['empty']],
        'payments' => ['n' => $stats['payments'], 'key' => '6', 'sub' => $stats['last_payment'] ? __('partners.open.last_payment', ['date' => $stats['last_payment']]) : $P['open']['empty']],
        'items' => ['n' => $stats['items'], 'key' => '7', 'sub' => $stats['top_item'] ? __('partners.open.top_item', ['name' => $stats['top_item']]) : $P['open']['empty']],
    ];
    // le sens du solde : un client doit (positif) ou a paye d'avance ; on doit a un fournisseur (positif) ou il nous doit
    $balanceSide = $balance === null ? null : ($balance['value'] > 0.005 ? 'pos' : ($balance['value'] < -0.005 ? 'neg' : 'zero'));
@endphp
@section('title', ($partner->name ?? $partner->code).' — '.__('common.app_name'))

@push('head')
<style>
    .crumbs { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; color: var(--muted); font-size: 14px; }
    .crumbs a { color: var(--muted); }
    .crumbs a:hover { color: var(--accent); }
    .print-drop { margin-inline-start: auto; }
    .print-drop summary, .crumbs .print-drop a { color: var(--text); }
    .print-drop .drop-menu a { padding: 8px 10px; border-radius: 8px; }
    .print-drop .drop-menu a:hover, .print-drop .drop-menu a:focus-visible { background: var(--row-hover); color: var(--text); }
    .who-head { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; margin-bottom: 16px; }
    .who-id { display: flex; flex-direction: column; gap: 6px; }
    .who-kind { margin: 0; color: var(--muted); font-weight: 600; }
    .who-name { margin: 0; font-size: clamp(24px, 3.6vw, 32px); line-height: 1.25; overflow-wrap: anywhere; }
    /* ltr pour le code, mais place au debut de la ligne de la page (a droite en arabe) */
    .who-code { margin: 0; font: 600 22px/1 var(--font-code); color: var(--muted); letter-spacing: .02em; align-self: flex-start; }
    .who-branch { display: inline-flex; align-items: center; gap: 8px; margin: 6px 0 0; font-weight: 600; }
    .facts { margin: 0; display: grid; gap: 8px; }
    .facts div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 7px; }
    .facts div:last-child { border-bottom: 0; padding-bottom: 0; }
    .facts dt { color: var(--muted); }
    .facts dd { margin: 0; font-weight: 600; text-align: end; overflow-wrap: anywhere; }

    .act-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .act-head h2 { font-size: 16px; margin: 0; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; padding: 3px 12px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 13.5px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    /* les chiffres de la periode, comme les boutons d'une fiche Odoo : un nombre, son libelle */
    .figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0; }
    .figures div { border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
    .figures dt { color: var(--muted); font-size: 13px; }
    .figures dd { margin: 2px 0 0; font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .figures dd small { display: block; font-size: 12.5px; font-weight: 500; color: var(--muted); }
    /* le solde, comme dans Gescomm : en bas de la carte du nom */
    .who-balance { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); display: grid; gap: 3px; }
    .who-balance .wb-label { display: flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 600; }
    .who-balance .wb-value { font: 800 clamp(28px, 3.6vw, 36px)/1.15 var(--font-code); font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; align-self: flex-start; }
    .who-balance small { color: var(--muted); font-size: 12.5px; }

    /* les boutons d'une fiche Odoo : ce qu'il y a derriere, combien, et le dernier en date */
    .open-btns { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 16px; }
    .open-btn { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 0 12px; padding: 12px 14px; text-align: start; border-radius: 10px; }
    .open-btn:hover { border-color: var(--accent); background: var(--accent-soft); }
    .open-btn .ic { width: 22px; height: 22px; color: var(--accent); }
    .open-btn .what { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
    .open-btn .what b { font-weight: 700; font-size: 15px; }
    .open-btn .what small { font-size: 12.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .open-btn .how-many { font: 700 27px/1 var(--font-code); direction: ltr; font-variant-numeric: tabular-nums; }
    .open-btn .go { width: 16px; height: 16px; color: var(--muted); }
    .open-btn:hover .go { color: var(--accent); }
    [dir=rtl] .flip { transform: scaleX(-1); }
    @media (max-width: 700px) { .who-head { grid-template-columns: 1fr; } }
    @media (max-width: 760px) { .open-btns { grid-template-columns: 1fr; } }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => $P['period']])
    @foreach ($opens as $tab => $open)
        @include('partials.key', ['keys' => ['Alt', $open['key']], 'run' => 'Alt+'.$open['key'], 'label' => $P['tabs'][$tab]])
    @endforeach
    @include('partials.key', ['keys' => ['Alt', 'P'], 'run' => 'Alt+P', 'label' => __('print.print')])
@endpush

@section('content')
<nav class="crumbs" aria-label="{{ __('documents.crumbs') }}">
    {{-- Echap ou clic : retour a la liste d'ou l'on vient, avec sa recherche (data-back, partials/keys) --}}
    <a href="{{ route("{$route}.index") }}" data-key="Escape" data-back>{{ $P['title'][$kind] }}</a>
    <span aria-hidden="true">/</span>
    <bdi>{{ $partner->name ?? $partner->code }}</bdi>
    @php($printPeriod = $period === 'all' ? null : $period)
    <details class="drop print-drop">
        <summary class="btn print-btn" data-key="Alt+P" title="{{ __('print.print') }} (Alt+P)">@include('partials.app-icon', ['name' => 'print']){{ __('print.print') }}</summary>
        <div class="drop-menu">
            <a href="{{ route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $printPeriod])) }}" target="_blank">{{ __("print.statement.title.{$kind}") }}</a>
            <a href="{{ route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $printPeriod, 'show' => 'payments'])) }}" target="_blank">{{ __("print.statement.title_payments.{$kind}") }}</a>
            <a href="{{ route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $printPeriod, 'show' => 'bons'])) }}" target="_blank">{{ __("print.statement.title_bons.{$kind}") }}</a>
            <a href="{{ route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $printPeriod, 'show' => 'items'])) }}" target="_blank">{{ __("print.statement.title_items.{$kind}") }}</a>
        </div>
    </details>
</nav>

<div class="who-head">
    <section class="card who-id" aria-labelledby="who-name">
        <p class="who-kind">{{ $P['one'][$kind] }}</p>
        <h1 class="who-name" id="who-name"><bdi>{{ $partner->name ?? $partner->code }}</bdi></h1>
        <p class="who-code ltr">{{ $partner->code }}</p>
        <p class="who-branch"><span class="bdot" style="--bh: {{ $branch->hue() }}"></span> {{ $branch->name }}
            @if ($partner->blocked)<span class="pill" title="{{ $P['blocked_help'] }}">{{ $P['blocked'] }}</span>@endif
        </p>
        @if ($balance !== null)
            <div class="who-balance" title="{{ $P['balance_help'] }}">
                <span class="wb-label">{{ $P['balance'] }} <span class="pill">{{ $P['balance_side'][$kind][$balanceSide] }}</span></span>
                <span class="wb-value">{{ $fmt($balance['value']) }}</span>
                <small>{{ __('partners.balance_how', ['billed' => $fmt($balance['billed']), 'paid' => $fmt($balance['paid'])]) }}</small>
                @if ($balance['closed'])<small>{{ __('exercises.balance_closed', ['name' => $balance['closed']]) }}</small>
                @elseif ($balance['as_of'])<small>{{ __('partners.balance_as_of', ['time' => $balance['as_of']->diffForHumans()]) }}</small>@endif
            </div>
        @endif
    </section>
    <section class="card" aria-label="{{ $P['details'] }}">
        @if ($facts === [])
            <p class="muted">{{ $P['no_details'] }}</p>
        @else
            <dl class="facts">
                @foreach ($facts as $key => $value)
                    <div><dt>{{ $P['facts'][$key] }}</dt><dd @class(['num' => in_array($key, ['phone', 'mobile', 'credit_limit', 'opening_balance'], true)])><bdi>{{ $value }}</bdi></dd></div>
                @endforeach
            </dl>
        @endif
    </section>
</div>

<section class="card" aria-labelledby="act-title">
    <div class="act-head">
        <h2 id="act-title">{{ $P['activity'][$kind] }}</h2>
        <nav class="chips" aria-label="{{ $P['period'] }}">
            @foreach (\App\Support\ProductActivity::PERIODS as $key => $days)
                <a href="{{ route("{$route}.show", ['partner' => $partner, 'period' => $key === 'all' ? null : $key]) }}" data-key="Alt+{{ $loop->iteration }}"
                   @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ __("stock.activity.periods.{$key}") }}</a>
            @endforeach
        </nav>
    </div>

    <dl class="figures">
        <div><dt>{{ $P['fig_bons'] }}</dt><dd class="num">{{ number_format($stats['bons'], 0, ',', ' ') }}<small>{{ $stats['last'] ? __('partners.last_bon', ['date' => $stats['last']]) : ' ' }}</small></dd></div>
        @if ($money)
            <div><dt>{{ $P['fig_amount'][$kind] }}</dt><dd class="num">{{ $fmt($stats['amount']) }}</dd></div>
        @endif
        <div>
            <dt>{{ $P['fig_returns'] }}</dt>
            <dd class="num">{{ $money ? $fmt($stats['returns_amount']) : number_format($stats['returns'], 0, ',', ' ') }}<small>{{ $stats['returns'] ? trans_choice('documents.return_lines', $stats['returns'], ['count' => $stats['returns']]) : $P['no_returns'] }}</small></dd>
        </div>
        @if ($money)
            <div>
                <dt>{{ $P['fig_paid'][$kind] }}</dt>
                <dd class="num">{{ $fmt($stats['paid']) }}<small>@foreach ($paidByType as $t)<bdi>{{ $t['type'] ?? '—' }}</bdi> {{ $fmt($t['amount']) }}@if (! $loop->last)<br>@endif @endforeach</small></dd>
            </div>
        @endif
    </dl>

    <div class="open-btns">
        @foreach ($opens as $tab => $open)
            <button type="button" class="open-btn" data-open="{{ $tab }}" data-key="Alt+{{ $open['key'] }}" aria-haspopup="dialog" title="{{ $P['open'][$tab] }} (Alt+{{ $open['key'] }})">
                <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    @switch($tab)
                        @case('bons')<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>@break
                        @case('payments')<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 9v.01M17 15v.01"/>@break
                        @default<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>
                    @endswitch
                </svg>
                <span class="what"><b>{{ $P['tabs'][$tab] }}</b><small><bdi>{{ $open['sub'] }}</bdi></small></span>
                <span class="how-many">{{ number_format($open['n'], 0, ',', ' ') }}</span>
                <svg class="go flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </button>
        @endforeach
    </div>
</section>

@include('partners._insights')
@include('partners._records-modal')
@endsection
