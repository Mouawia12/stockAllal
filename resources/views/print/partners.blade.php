@extends('layouts.print')

{{-- La liste des clients ou des fournisseurs sur papier (PartnerController::printList) : coordonnees, bons et versements sur la periode. --}}
@php
    $P = __('partners');
    $Pr = __('print');
    $L = $Pr['partners'];
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $title = $L['title'][$kind];
    $period = App\Support\PrintPeriod::label($window);
    $pageTitle = $title;
    $back = route("{$route}.index", array_filter(['q' => $q, 'f' => $filter === 'all' ? null : $filter]));
    $pageSize = $money ? 'A4 landscape' : 'A4';
    $cols = 3 + ($several ? 1 : 0) + 1;
@endphp

@section('tools')
    @include('print._period')
    @if ($money)
        @include('print._choice', ['label' => $Pr['sort'], 'param' => 'sort', 'current' => $sort, 'options' => ['name' => $L['sort_name'], 'amount' => $L['sort_amount']]])
    @endif
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    $period,
    $several ? __('print.scope', ['names' => $branchNames->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
    $q !== '' ? __('print.search_for', ['q' => $q]) : null,
    $filter === 'active' ? $L['filter_active'] : ($filter === 'blocked' ? $L['filter_blocked'] : null),
]])

@if ($outdated->isNotEmpty())<p class="note small">{{ __('print.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>@endif
@if ($rows->count() < $count)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($rows->count(), 0, ',', ' '), 'total' => number_format($count, 0, ',', ' ')]) }}</b></p>@endif

@if ($rows->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th>{{ __('documents.col_code') }}</th>
            <th style="width: 30%">{{ $P['col_name'][$kind] }}</th>
            @if ($several)<th>{{ __('documents.col_branch') }}</th>@endif
            <th>{{ $P['col_phone'] }}</th>
            <th style="width: 24%">{{ $L['address'] }}</th>
            <th class="n">{{ $P['col_bons'] }}</th>
            @if ($money)
                <th class="n">{{ $P['col_amount'][$kind] }}</th>
                <th class="n">{{ $L['paid'] }}</th>
            @endif
            <th class="n">{{ $P['col_last'] }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $r)
            <tr>
                <td style="white-space: nowrap"><span class="code ltr">{{ $r['code'] }}</span></td>
                <td><bdi>{{ $r['name'] ?? $r['code'] }}</bdi>@if ($r['blocked']) <span class="tag">{{ $P['blocked'] }}</span>@endif</td>
                @if ($several)<td>{{ $r['branch'] }}</td>@endif
                <td style="white-space: nowrap"><span class="ltr">{{ $r['phone'] }}</span></td>
                <td class="small"><bdi>{{ $r['address'] }}</bdi></td>
                <td class="n"><span class="num">{{ $r['bons'] ?: '' }}</span></td>
                @if ($money)
                    <td class="n"><span class="num">{{ $r['bons'] ? $fmt($r['amount']) : '' }}</span></td>
                    <td class="n"><span class="num">{{ $fmt($r['paid']) }}</span></td>
                @endif
                <td class="n"><span class="num">{{ $r['last'] }}</span></td>
            </tr>
        @endforeach
        <tr class="sum">
            <td colspan="{{ $cols }}">{{ __('print.count_total', ['count' => number_format($count, 0, ',', ' ')]) }}</td>
            <td></td>
            @if ($money)
                <td class="n"><span class="num">{{ $fmt($total) }}</span></td>
                <td class="n"><span class="num">{{ $fmt($totalPaid) }}</span></td>
            @endif
            <td></td>
        </tr>
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
