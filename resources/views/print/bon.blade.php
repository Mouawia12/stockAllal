@extends('layouts.print')

{{--
    Un bon de vente ou d'achat sur papier (DocumentController::printBon) : la societe, le client, les lignes, les totaux et
    le montant en lettres. Sans les prix (?prices=0, ou sans la permission) : une case devant chaque ligne, pour preparer
    ou controler la marchandise. Pas encore de solde du client : il attend la verification de agent/tools/check-balances.ps1.
--}}
@php
    $D = __('documents');
    $B = __('print.bon');
    $Pr = __('print');
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $qty = fn ($n) => $n === null ? '' : number_format($n, fmod((float) $n, 1) == 0 ? 0 : 2, ',', ' ');
    $showCode = $printPrefs['code'] === '1';
    $size = $printPrefs['size'];
    $pageSize = $size === 'a5' ? 'A5' : 'A4';
    $pageTitle = $D['bon'][$kind].' '.$Pr['no'].' '.$document->number;
    $back = route("{$route}.show", $document);
    $hasUnit = $lines->contains(fn ($l) => ! empty($l['unit']));
    $hasDiscount = $withPrices && $lines->contains(fn ($l) => ! empty($l['discount']));
    $phones = collect([$partner?->phone, $partner?->mobile])->filter()->join(' / ');
    $legal = collect(['rc', 'nif', 'nis', 'ai'])->filter(fn ($f) => $partner?->{$f})->map(fn ($f) => $B[$f].' : '.$partner->{$f});
    $words = $withPrices && app()->getLocale() === 'fr' && $document->amount !== null ? App\Support\AmountInWords::french($document->amount) : null;
    $when = $document->date?->format('d/m/Y').($document->time ? ' — '.substr($document->time, 0, 5) : '');
    $signatures = $withPrices
        ? ($kind === 'sale' ? [$B['sign_customer'], $B['sign_company']] : [$B['sign_received'], $B['sign_supplier']])
        : [$B['sign_prepared'], $B['sign_checked']];
@endphp

@push('head')
<style>
    .parties { display: flex; justify-content: space-between; gap: 8mm; margin-top: 3.5mm; }
    .party { min-width: 0; }
    .party-label { font-size: .86em; color: var(--soft); }
    .party-name { font-size: 1.3em; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
    .party-more { font-size: .95em; }
    .facts { flex: none; display: grid; grid-template-columns: auto auto; gap: .4mm 4mm; align-content: start; }
    .facts dt { color: var(--soft); }
    .facts dd { font-weight: 600; }
    td.check { width: 6mm; }
    .box { display: inline-block; width: 3.4mm; height: 3.4mm; border: .8pt solid #000; vertical-align: -.5mm; }
    tr.back td { font-style: normal; }
    .bottom { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; margin-top: 3mm; break-inside: avoid; }
    .sums { min-width: 0; }
    .words { margin-top: 1.5mm; font-weight: 600; }
    .words-label { font-weight: 400; display: block; color: var(--soft); }
    .totals { flex: none; display: grid; grid-template-columns: auto auto; gap: .6mm 6mm; }
    .totals dd { text-align: end; }
    .totals .grand { font-size: 1.5em; font-weight: 700; line-height: 1.2; border-top: 1.2pt solid #000; padding-top: 1mm; margin-top: .6mm; }
    .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 6mm; break-inside: avoid; }
    .sign { border-top: .5pt solid #000; padding-top: 1mm; min-height: {{ $size === 'a5' ? '14mm' : '20mm' }}; font-size: .9em; }
</style>
@endpush

@section('tools')
    @if ($money)
        @include('print._choice', ['label' => $Pr['prices'], 'param' => 'prices', 'current' => $withPrices ? '1' : '0', 'options' => ['1' => $Pr['with_prices'], '0' => $Pr['without_prices']]])
    @endif
    @include('print._choice', ['label' => $Pr['code'], 'param' => 'code', 'current' => $printPrefs['code'], 'options' => ['1' => $Pr['with_code'], '0' => $Pr['without_code']]])
    @include('print._choice', ['label' => $Pr['size'], 'param' => 'size', 'current' => $size, 'options' => ['a4' => 'A4', 'a5' => 'A5']])
@endsection

@section('content')
@include('print._head', ['letter' => $branch, 'title' => $D['bon'][$kind], 'big' => $document->number, 'sub' => [$when, $branch->print_header ? $branch->name : null]])

<section class="parties">
    <div class="party">
        <p class="party-label">{{ $D['col_partner'][$kind] }}</p>
        <p class="party-name"><bdi>{{ $partner?->name ?? $document->partner_code ?? '—' }}</bdi> @if ($partner?->name && $document->partner_code)<span class="code soft ltr">{{ $document->partner_code }}</span>@endif</p>
        @if ($partner?->address || $partner?->wilaya)<p class="party-more"><bdi>{{ collect([$partner?->address, $partner?->wilaya])->filter()->join(' — ') }}</bdi></p>@endif
        @if ($phones !== '')<p class="party-more num">{{ $phones }}</p>@endif
        @if ($partner?->activity)<p class="party-more">{{ $B['activity'] }} : <bdi>{{ $partner->activity }}</bdi></p>@endif
        @if ($legal->isNotEmpty())<p class="legal ltr">@foreach ($legal as $id)<span>{{ $id }}</span>@endforeach</p>@endif
    </div>
    <dl class="facts">
        @if ($document->payment)<dt>{{ $D['col_payment'] }}</dt><dd><bdi>{{ $document->payment }}</bdi></dd>@endif
        @if ($document->credit_days)<dt>{{ $D['credit_days'] }}</dt><dd>{{ trans_choice('documents.days', $document->credit_days, ['count' => $document->credit_days]) }}</dd>@endif
        @if ($document->served_by)<dt>{{ $D['col_served_by'] }}</dt><dd><bdi>{{ $document->served_by }}</bdi></dd>
        @elseif ($document->entered_by)<dt>{{ $D['entered_by'] }}</dt><dd><bdi>{{ $document->entered_by }}</bdi></dd>@endif
    </dl>
</section>

@if ($lines->isEmpty())
    <p class="empty">{{ $D['no_lines'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            @unless ($withPrices)<th></th>@endunless
            <th class="n">#</th>
            @if ($showCode)<th>{{ $D['col_code'] }}</th>@endif
            <th style="width: 100%">{{ $D['col_designation'] }}</th>
            @if ($hasUnit)<th>{{ $B['unit'] }}</th>@endif
            <th class="n">{{ $B['qty'] }}</th>
            @if ($withPrices)
                <th class="n">{{ $D['col_price'] }}</th>
                @if ($hasDiscount)<th class="n">{{ $B['discount'] }}</th>@endif
                <th class="n">{{ $D['col_total'] }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($lines as $l)
            <tr>
                @unless ($withPrices)<td class="check"><span class="box"></span></td>@endunless
                <td class="n soft">{{ $l['position'] ?? $loop->iteration }}</td>
                @if ($showCode)<td style="white-space: nowrap"><span class="code ltr">{{ $l['code'] }}</span></td>@endif
                <td><bdi>{{ $l['designation'] ?? ($showCode ? '—' : $l['code']) }}</bdi>@if ($l['back']) <span class="tag">{{ $D['return'] }}</span>@endif</td>
                @if ($hasUnit)<td><bdi>{{ $l['unit'] }}</bdi></td>@endif
                <td class="n"><span class="num">{{ $qty($l['qty']) }}</span></td>
                @if ($withPrices)
                    <td class="n"><span class="num">{{ $fmt($l['price']) }}</span></td>
                    @if ($hasDiscount)<td class="n"><span class="num">{{ $l['discount'] ? $qty($l['discount']) : '' }}</span></td>@endif
                    <td class="n"><span class="num">{{ $fmt($l['total']) }}</span></td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>

    <section class="bottom">
        <div class="sums">
            <p>{{ $B['products'] }} : <b class="num">{{ $lines->count() }}</b> — {{ $B['total_qty'] }} : <b class="num">{{ $qty($lines->sum('qty')) }}</b></p>
            @if ($words)<p class="words"><span class="words-label">{{ $B['words'] }}</span>{{ $words }}</p>@endif
            @if ($document->note)<p class="note">{{ $D['note'] }} : <bdi>{{ $document->note }}</bdi></p>@endif
        </div>
        @if ($withPrices)
            <dl class="totals">
                @if ($document->discount)
                    @if ($document->gross !== null)<dt>{{ $B['gross'] }}</dt><dd class="num">{{ $fmt($document->gross) }}</dd>@endif
                    <dt>{{ $B['total_discount'] }}</dt><dd class="num">{{ $fmt($document->discount) }}</dd>
                @endif
                <dt class="grand">{{ $B['total'] }}</dt><dd class="grand num">{{ $fmt($document->amount) }}</dd>
            </dl>
        @endif
    </section>
@endif

<section class="signs">
    @foreach ($signatures as $sign)
        <div class="sign">{{ $sign }}</div>
    @endforeach
</section>

@include('print._foot', ['letter' => $branch])
@endsection
