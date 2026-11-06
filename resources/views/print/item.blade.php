@extends('layouts.print')

{{--
    La fiche de stock d'un article sur papier (StockController::printItem) : son stock et ses prix dans chaque branche
    affichee, puis ses ventes et achats sur la periode (App\Support\ProductActivity), avec les memes permissions que sa page.
--}}
@php
    $I = __('print.item');
    $Pr = __('print');
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $qty = fn ($n) => $n === null ? '' : number_format($n, fmod((float) $n, 1) == 0 ? 0 : 2, ',', ' ');
    $others = array_values(array_diff($codes, [$code]));
    $pageTitle = $I['title'].' — '.$code;
    $back = route('stock.item', ['code' => $code]);
    $period = App\Support\PrintPeriod::label($window);
    $kinds = ['sale', 'purchase'];
    $moneyOf = fn (string $k) => $activity ? ($k === 'sale' ? $activity['show_sale'] : $activity['show_buy']) : false;
@endphp

@push('head')
<style>
    .item-name { margin-top: 3.5mm; font-size: 1.35em; font-weight: 700; line-height: 1.25; }
    .item-codes { font-size: .95em; }
</style>
@endpush

@section('tools')
    @include('print._period')
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $I['title'], 'big' => $code, 'sub' => [$period]])

<p class="item-name"><bdi>{{ $designation }}</bdi></p>
@if ($others !== [])<p class="item-codes code ltr">+ {{ implode(' + ', $others) }}</p>@endif

<h2 class="sec">{{ $I['stock_now'] }}</h2>
<table class="grid">
    <thead>
    <tr>
        <th style="width: 100%">{{ $I['branch'] }}</th>
        <th>{{ $D['col_code'] }}</th>
        <th class="n">{{ $I['out'] }}</th>
        @if ($show_sale)
            <th class="n">{{ __('stock.col_retail') }}</th>
            <th class="n">{{ __('stock.col_wholesale') }}</th>
        @endif
        @if ($show_buy)<th class="n">{{ __('stock.col_buy') }}</th>@endif
    </tr>
    </thead>
    <tbody>
    @foreach ($branches as $b)
        @forelse ($b['rows'] as $row)
            <tr>
                <td><bdi>{{ $b['branch'] }}</bdi></td>
                <td style="white-space: nowrap"><span class="code ltr">{{ $row['code'] }}</span></td>
                <td class="n"><b class="num">{{ $qty($row['stock']) }}</b></td>
                @if ($show_sale)
                    <td class="n"><span class="num">{{ $fmt($row['price'] ?? null) }}</span></td>
                    <td class="n"><span class="num">{{ $fmt($row['price_wholesale'] ?? null) }}</span></td>
                @endif
                @if ($show_buy)<td class="n"><span class="num">{{ $fmt($row['price_buy'] ?? null) }}</span></td>@endif
            </tr>
        @empty
            <tr>
                <td><bdi>{{ $b['branch'] }}</bdi></td>
                <td class="soft">—</td>
                <td class="n soft">—</td>
                @if ($show_sale)<td></td><td></td>@endif
                @if ($show_buy)<td></td>@endif
            </tr>
        @endforelse
    @endforeach
    <tr class="sum">
        <td colspan="2">{{ $Pr['total'] }}</td>
        <td class="n"><span class="num">{{ $qty($total) }}</span></td>
        @if ($show_sale)<td></td><td></td>@endif
        @if ($show_buy)<td></td>@endif
    </tr>
    </tbody>
</table>

@if ($activity && $activity['ready'] > 0)
    <h2 class="sec">{{ $I['activity'] }}</h2>
    @if ($activity['outdated']->isNotEmpty())<p class="note small">{{ __('print.outdated', ['names' => $activity['outdated']->join(__('stock.js.list_separator'))]) }}</p>@endif
    <table class="grid">
        <thead>
        <tr>
            <th style="width: 100%">{{ $I['kind'] }}</th>
            <th class="n">{{ $I['out'] }}</th>
            <th class="n">{{ $I['back'] }}</th>
            <th class="n">{{ $I['net'] }}</th>
            <th class="n">{{ $I['amount'] }}</th>
            <th class="n">{{ $I['average'] }}</th>
            @if ($activity['show_buy'])<th class="n">{{ $I['margin'] }}</th>@endif
        </tr>
        </thead>
        <tbody>
        @foreach ($kinds as $k)
            @php($row = $activity[$k])
            <tr>
                <td>{{ $I[$k] }}</td>
                <td class="n"><span class="num">{{ $qty($row['out']) }}</span></td>
                <td class="n"><span class="num">{{ $row['back'] ? $qty($row['back']) : '' }}</span></td>
                <td class="n"><b class="num">{{ $qty($row['net']) }}</b></td>
                <td class="n"><span class="num">{{ $fmt($row['amount']) }}</span></td>
                <td class="n"><span class="num">{{ $fmt($row['average']) }}</span></td>
                @if ($activity['show_buy'])<td class="n"><span class="num">{{ $fmt($row['margin']) }}</span></td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>

    @foreach ($kinds as $k)
        @if ($activity['lists'][$k]->isNotEmpty())
            <h2 class="sec">{{ $I['lines_'.$k] }}@if ($activity[$k]['lines'] > $activity['lists'][$k]->count()) <span class="soft small">({{ $activity['lists'][$k]->count() }} / {{ number_format($activity[$k]['lines'], 0, ',', ' ') }})</span>@endif</h2>
            <table class="grid">
                <thead>
                <tr>
                    <th>{{ $Pr['statement']['date'] }}</th>
                    <th>{{ $D['col_bon'] }}</th>
                    @if ($activity['several'])<th>{{ $D['col_branch'] }}</th>@endif
                    <th style="width: 100%">{{ $I['partner_'.$k] }}</th>
                    <th class="n">{{ $Pr['bon']['qty'] }}</th>
                    @if ($moneyOf($k))
                        <th class="n">{{ $D['col_price'] }}</th>
                        <th class="n">{{ $D['col_total'] }}</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach ($activity['lists'][$k] as $line)
                    <tr>
                        <td class="num">{{ $line['date'] }}</td>
                        <td><span class="code ltr">{{ $line['number'] }}</span></td>
                        @if ($activity['several'])<td>{{ $line['branch'] }}</td>@endif
                        <td><bdi>{{ $line['partner'] ?? '—' }}</bdi>@if ($line['partner_code']) <span class="code soft ltr">{{ $line['partner_code'] }}</span>@endif</td>
                        <td class="n">@if ($line['back'])<span class="tag">{{ $D['return'] }}</span> @endif<span class="num">{{ $qty($line['qty']) }}</span></td>
                        @if ($moneyOf($k))
                            <td class="n"><span class="num">{{ $fmt($line['price']) }}</span></td>
                            <td class="n"><span class="num">{{ $fmt($line['total']) }}</span></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
