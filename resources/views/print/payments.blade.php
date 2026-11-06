@extends('layouts.print')

{{-- Les versements de tous les clients (ou a tous les fournisseurs) sur papier (PartnerController::printPayments) : jour par jour, total par type. --}}
@php
    $Pr = __('print');
    $V = $Pr['payments'];
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $title = $V['title'][$kind];
    $period = App\Support\PrintPeriod::label($window);
    $pageTitle = $title.' — '.$period;
    $back = route("{$route}.index");
    $cols = 6 + ($several ? 1 : 0) + ($money ? 1 : 0);
@endphp

@section('tools')
    @include('print._period')
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    $period,
    $several ? __('print.scope', ['names' => $branchNames->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
]])

@if ($outdated->isNotEmpty())<p class="note small">{{ __('print.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>@endif
@if ($shown < $count)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($shown, 0, ',', ' '), 'total' => number_format($count, 0, ',', ' ')]) }}</b></p>@endif

@if ($groups->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th>{{ $V['time'] }}</th>
            @if ($several)<th>{{ __('documents.col_branch') }}</th>@endif
            <th style="width: 40%">{{ $V['partner'][$kind] }}</th>
            <th>{{ $V['type'] }}</th>
            <th>{{ $V['cheque'] }}</th>
            <th>{{ $V['bon'] }}</th>
            <th style="width: 30%">{{ $V['note'] }}</th>
            @if ($money)<th class="n">{{ $V['amount'] }}</th>@endif
        </tr>
        </thead>
        @foreach ($groups as $day)
            <tbody>
            <tr class="group">
                <th colspan="{{ $cols }}">
                    <span class="group-in">
                        <span>{{ $day['name'] }} <span class="num">{{ $day['date'] }}</span></span>
                        <span>@if ($money)<span class="num">{{ $fmt($day['amount']) }}</span>@else<span class="num">{{ $day['n'] }}</span>@endif</span>
                    </span>
                </th>
            </tr>
            @foreach ($day['rows'] as $r)
                <tr>
                    <td class="num">{{ $r['time'] }}</td>
                    @if ($several)<td>{{ $r['branch'] }}</td>@endif
                    <td><bdi>{{ $r['partner'] ?? '—' }}</bdi>@if ($r['partner_code']) <span class="code soft ltr">{{ $r['partner_code'] }}</span>@endif</td>
                    <td><bdi>{{ $r['type'] }}</bdi></td>
                    <td class="num small">{{ $r['cheque'] }}</td>
                    <td><span class="code ltr">{{ $r['bon'] }}</span></td>
                    <td class="small"><bdi>{{ $r['note'] }}</bdi></td>
                    @if ($money)<td class="n"><span class="num">{{ $fmt($r['amount']) }}</span></td>@endif
                </tr>
            @endforeach
            </tbody>
        @endforeach
        <tbody>
        @if ($byType->count() > 1)
            @foreach ($byType as $t)
                <tr>
                    <td colspan="{{ $cols - 1 }}"><bdi>{{ $t['type'] ?? '—' }}</bdi> ({{ $t['n'] }})</td>
                    <td class="n"><span class="num">{{ $fmt($t['amount']) }}</span></td>
                </tr>
            @endforeach
        @endif
        <tr class="sum">
            <td colspan="{{ $cols - ($money ? 1 : 0) }}">{{ __('print.count_total', ['count' => number_format($count, 0, ',', ' ')]) }}</td>
            @if ($money)<td class="n"><span class="num">{{ $fmt($total) }}</span></td>@endif
        </tr>
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
