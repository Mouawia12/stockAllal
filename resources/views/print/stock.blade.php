@extends('layouts.print')

{{--
    L'etat du stock sur papier (StockController::printList) : le stock de chaque branche affichee, le total et les prix
    que le compte peut voir. La fiche d'inventaire (?mode=count) : une colonne vide pour ce qui est compte, sans prix.
    Une quantite ne porte pas de couleur ici : le papier est noir ; negatif = le signe moins.
--}}
@php
    $S = __('print.stock');
    $Pr = __('print');
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $qty = fn ($n) => $n === null ? '' : number_format($n, fmod((float) $n, 1) == 0 ? 0 : 2, ',', ' ');
    $title = $mode === 'count' ? $S['title_count'] : $S['title'];
    $pageTitle = $title;
    $back = route('stock.index', array_filter(['q' => $q, 'f' => $filter === 'all' ? null : $filter]));
    $extra = $columns->count() + ($showSale ? 2 : 0) + ($showBuy ? 1 : 0);
    $pageSize = $extra > 3 ? 'A4 landscape' : 'A4';
    $hasUnit = $results->contains(fn ($r) => ! empty($r->unit));
    $lead = 2 + ($hasUnit ? 1 : 0);
    $selectedFilter = $filter === 'all' ? null : __('print.stock.filter', ['name' => __("stock.filters.{$filter}")]);
@endphp

@section('tools')
    @include('print._choice', ['label' => $S['mode'], 'param' => 'mode', 'current' => $mode, 'options' => ['list' => $S['mode_list'], 'count' => $S['mode_count']]])
    @include('print._choice', ['label' => $Pr['sort'], 'param' => 'sort', 'current' => $sort, 'options' => ['code' => $S['sort_code'], 'name' => $S['sort_name']]])
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    now()->format('d/m/Y'),
    trans_choice('print.stock.items', $matched, ['count' => number_format($matched, 0, ',', ' ')]),
    $branchNames->count() > 1 ? __('print.scope', ['names' => $branchNames->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
    $q !== '' ? __('print.search_for', ['q' => $q]) : null,
    $selectedFilter,
    ($showSale || $showBuy) && $mainBranch ? __('print.stock.prices_of', ['name' => $mainBranch->name]) : null,
]])

@if ($results->count() < $matched)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($results->count(), 0, ',', ' '), 'total' => number_format($matched, 0, ',', ' ')]) }}</b></p>@endif

@if ($results->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th>{{ __('documents.col_code') }}</th>
            <th style="width: 100%">{{ __('documents.col_designation') }}</th>
            @if ($hasUnit)<th>{{ $S['unit'] }}</th>@endif
            @foreach ($columns as $b)<th class="n"><bdi>{{ $b->name }}</bdi></th>@endforeach
            <th class="n">{{ $S['total'] }}</th>
            @if ($showSale)
                <th class="n">{{ __('stock.col_retail') }}</th>
                <th class="n">{{ __('stock.col_wholesale') }}</th>
            @endif
            @if ($showBuy)<th class="n">{{ __('stock.col_buy') }}</th>@endif
            @if ($mode === 'count')<th class="n" style="min-width: 26mm">{{ $S['counted'] }}</th>@endif
        </tr>
        </thead>
        <tbody>
        @foreach ($results as $r)
            <tr>
                <td style="white-space: nowrap"><span class="code ltr">{{ $r->code }}</span>@foreach ($r->merged as $other) <span class="code soft small ltr">+ {{ $other }}</span>@endforeach</td>
                <td><bdi>{{ $r->designation }}</bdi></td>
                @if ($hasUnit)<td><bdi>{{ $r->unit }}</bdi></td>@endif
                @foreach ($columns as $b)<td class="n"><span class="num">{{ $qty($r->by_branch[$b->id] ?? null) }}</span></td>@endforeach
                <td class="n"><b class="num">{{ $qty($r->total) }}</b></td>
                @if ($showSale)
                    <td class="n"><span class="num">{{ $fmt($r->price) }}</span></td>
                    <td class="n"><span class="num">{{ $fmt($r->wholesale) }}</span></td>
                @endif
                @if ($showBuy)<td class="n"><span class="num">{{ $fmt($r->buy) }}</span></td>@endif
                @if ($mode === 'count')<td></td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
