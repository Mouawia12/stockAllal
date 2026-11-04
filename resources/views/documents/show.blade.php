@extends('layouts.app')

{{--
    Un bon de vente ou d'achat (DocumentController), comme le "Bon de Livraison" de Gescomm : le numero, le jour,
    le client et qui l'a servi, puis les lignes avec le stock actuel de chaque article dans cette branche (si le compte
    voit ce stock), puis les totaux. Une ligne negative est un retour : "مرتجع", jamais en jaune.
--}}
@php
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $partnerName = $partner?->name;
    $phones = collect([$partner?->phone, $partner?->mobile])->filter()->join(' / ');
@endphp
@section('title', $D['bon'][$kind].' '.$document->number.' — '.__('common.app_name'))

@push('head')
<style>
    .crumbs { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; color: var(--muted); font-size: 14px; }
    .crumbs a { color: var(--muted); }
    .crumbs a:hover { color: var(--accent); }
    .bon-head { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; margin-bottom: 16px; }
    .bon-id { display: flex; flex-direction: column; gap: 6px; }
    .bon-kind { margin: 0; color: var(--muted); font-weight: 600; }
    /* ltr pour le numero, mais place au debut de la ligne de la page (a droite en arabe) */
    .bon-number { margin: 0; font: 700 clamp(40px, 6vw, 56px)/1 var(--font-code); letter-spacing: .01em; direction: ltr; unicode-bidi: isolate; align-self: flex-start; }
    .bon-when { margin: 4px 0 0; font-size: 15px; }
    .bon-when .num { color: var(--muted); }
    .bon-branch { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-weight: 600; }
    .bon-flags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
    .facts { margin: 0; display: grid; gap: 8px; }
    .facts div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 7px; }
    .facts div:last-child { border-bottom: 0; padding-bottom: 0; }
    .facts dt { color: var(--muted); }
    .facts dd { margin: 0; font-weight: 600; text-align: end; }
    .lines th.n, .lines td.n { text-align: end; }
    .lines tr.back td { color: var(--muted); }
    .lines a.code { color: var(--text); }
    .facts a.who { color: var(--text); text-decoration: underline dotted var(--muted); text-underline-offset: 3px; }
    .facts a.who:hover { color: var(--accent); }
    .lines a.code:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    /* les totaux sous la colonne des montants, comme le pied d'un bon */
    .totals { display: grid; grid-template-columns: auto auto; justify-content: end; gap: 6px 28px; margin: 14px 0 0; padding-top: 12px; border-top: 2px solid var(--border); }
    .totals dt { color: var(--muted); }
    .totals dd { margin: 0; text-align: end; font-variant-numeric: tabular-nums; }
    .totals .grand { font-size: 18px; font-weight: 700; color: var(--text); }
    @media (max-width: 700px) { .bon-head { grid-template-columns: 1fr; } }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
@endpush

@section('content')
<nav class="crumbs" aria-label="{{ $D['crumbs'] }}">
    {{-- Echap ou clic : retour a la liste d'ou l'on vient, avec sa recherche et sa periode (data-back, partials/keys) --}}
    <a href="{{ route("{$route}.index") }}" data-key="Escape" data-back>{{ $D['title'][$kind] }}</a>
    <span aria-hidden="true">/</span>
    <span class="num">{{ $document->number }}</span>
</nav>

<div class="bon-head">
    <section class="card bon-id" aria-labelledby="bon-number">
        <p class="bon-kind">{{ $D['bon'][$kind] }}</p>
        <h1 class="bon-number" id="bon-number">{{ $document->number }}</h1>
        <p class="bon-when">{{ $day['name'] }} <span class="num">{{ $day['date'] }}@if ($document->time) — {{ substr($document->time, 0, 5) }}@endif</span></p>
        <p class="bon-branch"><span class="bdot" style="--bh: {{ $branch->hue() }}"></span> {{ $branch->name }}</p>
        @if ($returns > 0)
            <div class="bon-flags"><span class="pill">{{ trans_choice('documents.return_lines', $returns, ['count' => $returns]) }}</span></div>
        @endif
    </section>
    <section class="card" aria-label="{{ $D['details'] }}">
        <dl class="facts">
            <div>
                <dt>{{ $D['col_partner'][$kind] }}</dt>
                <dd>@if ($partnerUrl)<a class="who" href="{{ $partnerUrl }}"><bdi>{{ $partnerName ?? $document->partner_code }}</bdi></a>@else<bdi>{{ $partnerName ?? $document->partner_code ?? '—' }}</bdi>@endif @if ($partnerName && $document->partner_code) <span class="muted small ltr">{{ $document->partner_code }}</span>@endif</dd>
            </div>
            @if ($phones !== '')<div><dt>{{ $D['phone'] }}</dt><dd class="num">{{ $phones }}</dd></div>@endif
            @if ($partner?->wilaya)<div><dt>{{ $D['wilaya'] }}</dt><dd><bdi>{{ $partner->wilaya }}</bdi></dd></div>@endif
            @if ($document->served_by)<div><dt title="Servi par">{{ $D['col_served_by'] }}</dt><dd><bdi>{{ $document->served_by }}</bdi></dd></div>@endif
            @if ($document->payment)<div><dt title="Réglement">{{ $D['col_payment'] }}</dt><dd><bdi>{{ $document->payment }}</bdi></dd></div>@endif
            @if ($document->credit_days)<div><dt title="Délais du Crédit">{{ $D['credit_days'] }}</dt><dd>{{ trans_choice('documents.days', $document->credit_days, ['count' => $document->credit_days]) }}</dd></div>@endif
            @if ($document->entered_by)<div><dt title="Saisi Par">{{ $D['entered_by'] }}</dt><dd><bdi>{{ $document->entered_by }}</bdi></dd></div>@endif
            @if ($document->note)<div><dt title="Observation">{{ $D['note'] }}</dt><dd><bdi>{{ $document->note }}</bdi></dd></div>@endif
        </dl>
    </section>
</div>

<section class="card" aria-label="{{ $D['col_lines'] }}">
    @if ($lines->isEmpty())
        <p class="muted">{{ $D['no_lines'] }}</p>
    @else
        <table class="lines">
            <thead>
            <tr>
                <th scope="col" class="hide-sm">#</th>
                <th scope="col">{{ $D['col_code'] }}</th>
                <th scope="col">{{ $D['col_designation'] }}</th>
                <th scope="col" class="n">{{ $D['col_qty'] }}</th>
                @if ($money)
                    <th scope="col" class="n hide-sm">{{ $D['col_price'] }}</th>
                    <th scope="col" class="n hide-sm">{{ $D['col_discount'] }}</th>
                    <th scope="col" class="n">{{ $D['col_total'] }}</th>
                @endif
                @if ($seesStock)<th scope="col" class="n hide-sm" title="{{ $D['stock_help'] }}">{{ $D['col_stock'] }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $l)
                <tr @class(['back' => $l['back']])>
                    <td class="hide-sm muted num">{{ $l['position'] }}</td>
                    <td><a class="code ltr" href="{{ route('stock.item', ['code' => $l['code']]) }}">{{ $l['code'] }}</a></td>
                    <td><bdi>{{ $l['designation'] ?? '—' }}</bdi></td>
                    <td class="n">
                        @if ($l['back'])<span class="pill">{{ $D['return'] }}</span>@endif
                        <span class="num">{{ $fmt($l['qty']) }}</span>
                    </td>
                    @if ($money)
                        <td class="n hide-sm"><span class="num">{{ $fmt($l['price']) }}</span></td>
                        <td class="n hide-sm muted"><span class="num">{{ $l['discount'] ? $fmt($l['discount']) : '' }}</span></td>
                        <td class="n"><span class="num">{{ $fmt($l['total']) }}</span></td>
                    @endif
                    @if ($seesStock)
                        <td class="n hide-sm">@if ($l['stock'] !== null)<span class="qty {{ $l['state'] }}">{{ $fmt($l['stock']) }}</span>@else<span class="muted">—</span>@endif</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($money)
            <dl class="totals">
                @if ($document->discount)
                    @if ($document->gross !== null)<dt>{{ $D['gross'] }}</dt><dd class="num">{{ $fmt($document->gross) }}</dd>@endif
                    <dt title="Total Remise">{{ $D['discount'] }}</dt><dd class="num">{{ $fmt($document->discount) }}</dd>
                @endif
                <dt>{{ $D['amount'] }}</dt><dd class="num grand">{{ $fmt($document->amount) }}</dd>
                @if ($margin !== null)
                    <dt title="{{ __('stock.activity.margin_help') }}">{{ __('stock.activity.margin') }}@if ($marginPartial) *@endif</dt><dd class="num">{{ $fmt($margin) }}</dd>
                @endif
            </dl>
            @if ($margin !== null && $marginPartial)
                <p class="muted small">* {{ __('stock.activity.margin_partial') }}</p>
            @endif
        @endif
    @endif
</section>
@endsection
