@extends('layouts.print')

{{-- Les bons d'une periode sur papier (DocumentController::printList) : jour par jour, le total de chaque jour et de la periode. --}}
@php
    $D = __('documents');
    $Pr = __('print');
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $title = $Pr['journal']['title'][$kind];
    $period = App\Support\PrintPeriod::label($window);
    $pageTitle = $title.' — '.$period;
    $back = route("{$route}.index", array_filter(['q' => $q, 'f' => $filter === 'all' ? null : $filter]));
    $cols = 3 + ($several ? 1 : 0) + 1 + ($money ? 1 : 0) + 1;
@endphp

@section('tools')
    @include('print._period')
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    $period,
    $several ? __('print.scope', ['names' => $branchNames->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
    $q !== '' ? __('print.search_for', ['q' => $q]) : null,
    $filter === 'returns' ? $Pr['journal']['returns_only'] : null,
]])

@if ($outdated->isNotEmpty())<p class="note small">{{ __('print.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>@endif
@if ($shown < $count)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($shown, 0, ',', ' '), 'total' => number_format($count, 0, ',', ' ')]) }}</b></p>@endif

@if ($groups->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th>{{ $D['col_bon'] }}</th>
            <th>{{ $D['col_time'] }}</th>
            @if ($several)<th>{{ $D['col_branch'] }}</th>@endif
            <th style="width: 100%">{{ $D['col_partner'][$kind] }}</th>
            <th>{{ $kind === 'sale' ? $D['col_served_by'] : $D['col_payment'] }}</th>
            <th class="n">{{ $D['col_lines'] }}</th>
            @if ($money)<th class="n">{{ $D['col_amount'] }}</th>@endif
        </tr>
        </thead>
        @foreach ($groups as $day)
            <tbody>
            <tr class="group">
                <th colspan="{{ $cols }}">
                    <span class="group-in">
                        <span>{{ $day['label']['name'] }} <span class="num">{{ $day['label']['date'] }}</span></span>
                        <span>{{ trans_choice('documents.bons', $day['bons'], ['count' => $day['bons']]) }}@if ($money) — <span class="num">{{ $fmt($day['amount']) }}</span>@endif</span>
                    </span>
                </th>
            </tr>
            @foreach ($day['rows'] as $r)
                <tr>
                    <td><span class="code ltr">{{ $r['number'] }}</span></td>
                    <td class="num">{{ $r['time'] }}</td>
                    @if ($several)<td>{{ $r['branch'] }}</td>@endif
                    <td><bdi>{{ $r['partner'] ?? '—' }}</bdi>@if ($r['partner_code']) <span class="code soft ltr">{{ $r['partner_code'] }}</span>@endif</td>
                    <td class="soft"><bdi>{{ $kind === 'sale' ? $r['served_by'] : $r['payment'] }}</bdi></td>
                    <td class="n">@if ($r['returns'] > 0)<span class="tag">{{ $D['return'] }}</span> @endif<span class="num">{{ $r['lines'] }}</span></td>
                    @if ($money)<td class="n"><span class="num">{{ $fmt($r['amount']) }}</span></td>@endif
                </tr>
            @endforeach
            </tbody>
        @endforeach
        <tbody>
        <tr class="sum">
            <td colspan="{{ $cols - ($money ? 1 : 0) }}">{{ __('print.count_total', ['count' => trans_choice('documents.bons', $count, ['count' => number_format($count, 0, ',', ' ')])]) }}</td>
            @if ($money)<td class="n"><span class="num">{{ $fmt($total) }}</span></td>@endif
        </tr>
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
