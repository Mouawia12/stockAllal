@extends('layouts.app')

{{--
    Fiche d'un client ou d'un fournisseur (PartnerController) : qui il est, ce qu'il a achete sur la periode
    (bons, montant, retours, versements), puis trois onglets : ses bons, ses versements, les articles qu'il prend le plus.
    Pas de solde ici tant que le calcul de Gescomm n'est pas verifie (agent/tools/check-balances.ps1).
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
    $tabs = ['bons' => $stats['bons'], 'payments' => $stats['payments'], 'items' => $items->count()];
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
    .balance-note { margin: 12px 0 0; }

    .tabs { display: flex; gap: 6px; margin: 18px 0 0; border-bottom: 1px solid var(--border); overflow-x: auto; }
    .tabs button { border: 0; border-bottom: 2px solid transparent; border-radius: 0; background: none; color: var(--muted); padding: 8px 12px; display: inline-flex; gap: 8px; align-items: center; white-space: nowrap; }
    .tabs button[aria-selected=true] { color: var(--text); border-bottom-color: var(--accent); font-weight: 600; }
    .panel { padding-top: 6px; }
    .panel th.n, .panel td.n { text-align: end; white-space: nowrap; }
    .panel a.bon { font-family: var(--font-code); font-weight: 600; font-size: 16px; color: var(--text); }
    .panel a.bon:hover, .panel a.code:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .panel a.code { color: var(--text); }
    @media (max-width: 700px) { .who-head { grid-template-columns: 1fr; } }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => $P['period']])
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
    <p class="balance-note muted small">{{ $P['balance_soon'] }}</p>

    <div class="tabs" role="tablist" aria-label="{{ $P['activity'][$kind] }}">
        @foreach ($tabs as $tab => $n)
            <button type="button" role="tab" id="tab-{{ $tab }}" aria-controls="panel-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" @if (! $loop->first) tabindex="-1" @endif>
                {{ $P['tabs'][$tab] }} <span class="pill num">{{ number_format($n, 0, ',', ' ') }}</span>
            </button>
        @endforeach
    </div>

    <div class="panel" role="tabpanel" id="panel-bons" aria-labelledby="tab-bons">
        @if ($bons->isEmpty())
            <p class="muted">{{ $P['none_bons'] }}</p>
        @else
            <table>
                <thead>
                <tr>
                    <th scope="col">{{ __('documents.col_bon') }}</th>
                    <th scope="col">{{ $P['col_date'] }}</th>
                    <th scope="col" class="n">{{ __('documents.col_lines') }}</th>
                    @if ($money)<th scope="col" class="n">{{ __('documents.col_amount') }}</th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($bons as $b)
                    <tr>
                        <td><a class="bon ltr" href="{{ $b['url'] }}">{{ $b['number'] }}</a></td>
                        <td class="num">{{ $b['date'] }} <span class="muted small">{{ $b['time'] }}</span></td>
                        <td class="n">@if ($b['returns'] > 0)<span class="pill">{{ __('documents.return') }}</span> @endif<span class="num">{{ $b['lines'] }}</span></td>
                        @if ($money)<td class="n"><span class="num">{{ $fmt($b['amount']) }}</span></td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($stats['bons'] > $bons->count())
                <p class="muted small">{{ __('partners.latest', ['shown' => $bons->count(), 'total' => number_format($stats['bons'], 0, ',', ' ')]) }}
                    <a href="{{ route("{$docRoute}.index", ['q' => $partner->code, 'period' => 'all']) }}">{{ $P['all_bons'] }}</a></p>
            @endif
        @endif
    </div>

    <div class="panel" role="tabpanel" id="panel-payments" aria-labelledby="tab-payments" hidden>
        @if ($payments->isEmpty())
            <p class="muted">{{ $P['none_payments'] }}</p>
        @else
            <table>
                <thead>
                <tr>
                    <th scope="col">{{ $P['col_date'] }}</th>
                    <th scope="col" title="TYPEVER">{{ $P['col_type'] }}</th>
                    @if ($money)<th scope="col" class="n">{{ $P['col_paid'] }}</th>@endif
                    <th scope="col" class="hide-sm">{{ $P['col_cheque'] }}</th>
                    <th scope="col">{{ __('documents.col_bon') }}</th>
                    <th scope="col" class="hide-sm">{{ __('documents.note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($payments as $p)
                    <tr>
                        <td class="num">{{ $p['date'] }} <span class="muted small">{{ $p['time'] }}</span></td>
                        <td><span class="pill"><bdi>{{ $p['type'] ?? '—' }}</bdi></span></td>
                        @if ($money)<td class="n"><span class="num">{{ $fmt($p['amount']) }}</span></td>@endif
                        <td class="hide-sm"><span class="num">{{ $p['cheque'] }}</span>@if ($p['paid_on']) <span class="muted small">{{ __('partners.paid_on', ['date' => $p['paid_on']]) }}</span>@endif</td>
                        <td>@if ($p['bon_url'])<a class="bon ltr" href="{{ $p['bon_url'] }}">{{ $p['bon'] }}</a>@else<span class="num muted">{{ $p['bon'] ?: '' }}</span>@endif</td>
                        <td class="hide-sm muted"><bdi>{{ $p['note'] }}</bdi></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($stats['payments'] > $payments->count())
                <p class="muted small">{{ __('partners.latest', ['shown' => $payments->count(), 'total' => number_format($stats['payments'], 0, ',', ' ')]) }}</p>
            @endif
        @endif
    </div>

    <div class="panel" role="tabpanel" id="panel-items" aria-labelledby="tab-items" hidden>
        @if ($items->isEmpty())
            <p class="muted">{{ $P['none_items'] }}</p>
        @else
            <table>
                <thead>
                <tr>
                    <th scope="col">{{ __('documents.col_code') }}</th>
                    <th scope="col">{{ __('documents.col_designation') }}</th>
                    <th scope="col" class="n" title="{{ $P['net_help'] }}">{{ $P['col_net'] }}</th>
                    @if ($money)<th scope="col" class="n">{{ __('documents.col_amount') }}</th>@endif
                    <th scope="col" class="n hide-sm">{{ __('documents.col_lines') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($items as $i)
                    <tr>
                        <td><a class="code ltr" href="{{ route('stock.item', ['code' => $i['code']]) }}">{{ $i['code'] }}</a></td>
                        <td><bdi>{{ $i['designation'] ?? '—' }}</bdi></td>
                        <td class="n"><span class="num">{{ $fmt($i['qty']) }}</span></td>
                        @if ($money)<td class="n"><span class="num">{{ $fmt($i['amount']) }}</span></td>@endif
                        <td class="n hide-sm muted"><span class="num">{{ $i['lines'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>
@endsection

@push('scripts')
<script>
(() => {
    // onglets facon Odoo ; les fleches passent de l'un a l'autre dans le sens de la langue
    const tabs = [...document.querySelectorAll('.tabs [role=tab]')];
    const pick = tab => tabs.forEach(t => {
        const on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => pick(tab));
        tab.addEventListener('keydown', e => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            const forward = (e.key === 'ArrowLeft') === (document.dir === 'rtl');
            const next = tabs[(i + (forward ? 1 : tabs.length - 1)) % tabs.length];
            pick(next);
            next.focus();
        });
    });
})();
</script>
@endpush
