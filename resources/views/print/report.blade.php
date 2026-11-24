@extends('layouts.print')

{{--
    Un rapport de "لوحة القيادة" sur papier (ReportController::print) : toute la liste, dans le tri, le filtre et la
    recherche de l'ecran, ses chiffres en haut et ses totaux en bas. Les montants au centime, comme Gescomm. Encre noire :
    une quantite ne porte pas de couleur, une part s'ecrit en %, la classe ABC en lettre.
--}}
@use('App\Support\Reports')
@use('Illuminate\Support\Carbon')
@php
    $R = __('reports');
    $Pr = __('print');
    $locale = app()->getLocale();
    $title = $R['titles'][$report];
    $pageTitle = $title;
    $back = route('dashboard.reports', ['report' => $report] + $periodState + $listState);
    $pageSize = count($columns) > 7 ? 'A4 landscape' : 'A4';
    $n0 = fn ($v) => $v === null ? '' : number_format(round($v), 0, ',', ' ');
    $n2 = fn ($v) => $v === null ? '' : number_format($v, 2, ',', ' ');
    $qty = fn ($v) => $v === null ? '' : number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' ');
    $pct = fn ($v) => $v === null ? '' : number_format($v, 1, ',', ' ').' %';
    $date = fn ($v) => $v === null ? '' : Carbon::parse($v)->format('d/m/Y');
    $when = fn (string $key) => strlen($key) === 7
        ? Carbon::parse($key.'-01')->locale($locale)->translatedFormat('F Y')
        : Carbon::parse($key)->locale($locale)->translatedFormat('l d/m/Y');
    $numeric = fn (array $col) => ! in_array($col['type'], ['item', 'partner', 'text', 'branch', 'period', 'class'], true);
    // une valeur ecrite comme sa colonne (les lignes, puis le total)
    $write = fn (array $col, $v) => match ($col['type']) {
        'int' => $n0($v),
        'qty', 'stock' => $qty($v),
        'pct', 'share' => $pct($v),
        'date' => $date($v),
        'idle' => $v === Reports::NEVER ? $R['never'] : $n0($v),
        default => $n2($v),
    };
    $show = fn (string $type, $v) => match ($type) {
        'int' => $n0($v),
        'qty' => $qty($v),
        'pct' => $v === null ? '' : number_format($v, 1, ',', ' '),
        'period' => $when($v),
        default => $n2($v),
    };
    // les cellules d'une ligne, sans les blancs du gabarit : une liste de milliers de lignes pese trois fois moins
    // (lignes instables : chaque octet compte). Tout texte passe par e().
    $cells = function (array $r) use ($columns, $names, $write, $when, $R) {
        $html = '';
        foreach ($columns as $col) {
            $v = $r[$col['key']] ?? null;
            $html .= match ($col['type']) {
                'item' => '<td class="k"><span class="code ltr">'.e($r['code']).'</span></td><td><bdi>'.e($r['designation']).'</bdi></td>',
                'partner' => '<td class="k"><span class="code ltr">'.e($r['code']).'</span></td><td><bdi>'.e($r['name']).'</bdi></td>',
                'branch' => '<td><bdi>'.e($names[$r['branch_id']] ?? '').'</bdi></td>',
                'period' => '<td class="k">'.e($when($v)).'</td>',
                'text' => '<td><bdi>'.e($v ?? $R['unknown']).'</bdi></td>',
                'class' => '<td>'.e($v ? strtoupper($v) : '').'</td>',
                default => '<td class="n">'.($col['strong'] ? '<b class="num">' : '<span class="num">').e($write($col, $v)).($col['strong'] ? '</b>' : '</span>').'</td>',
            };
        }

        return new Illuminate\Support\HtmlString($html);
    };
    $sorted = collect($columns)->firstWhere('key', $sort);
    $filterName =$filter === ($filters[0] ?? 'all') ? null : ($R['filters_of'][$report][$filter] ?? $R['filters'][$filter]);
@endphp

@push('head')
<style>
    /* un code, un jour : jamais coupe en deux lignes */
    table.grid td.k { white-space: nowrap; }
</style>
@endpush

@section('tools')
    @if ($periodic)
        {{-- la periode : du ... au ... ; "period" et "day" de l'ecran laissent la place aux dates choisies ici --}}
        <form method="get" action="{{ url()->current() }}">
            @foreach (request()->except(['from', 'to', 'auto', 'period', 'day']) as $name => $value)
                @if (is_string($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <label>{{ $Pr['from'] }} <input type="date" name="from" value="{{ $window['from'] }}"></label>
            <label>{{ $Pr['to'] }} <input type="date" name="to" value="{{ $window['to'] }}"></label>
            <button type="submit">{{ $Pr['apply'] }}</button>
        </form>
    @endif
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    $periodic ? $windowLabel : __('reports.print.as_of', ['date' => Carbon::parse($today)->format('d/m/Y')]),
    $report === 'dormant' ? __('reports.print.dormant', ['days' => $days]) : null,
    trans_choice('reports.rows', $matched, ['count' => number_format($matched, 0, ',', ' ')]),
    $scope->count() > 1 ? __('print.scope', ['names' => $scope->pluck('name')->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
    $q !== '' ? __('print.search_for', ['q' => $q]) : null,
    $filterName ? __('reports.print.filter', ['name' => $filterName]) : null,
    $sorted ? __('reports.print.sorted', ['name' => $R['cols'][$sorted['label']]]) : null,
]])

@if ($outdated->isNotEmpty())<p class="note soft small">{{ __('print.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>@endif
@if ($rows->count() < $matched)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($rows->count(), 0, ',', ' '), 'total' => number_format($matched, 0, ',', ' ')]) }}</b></p>@endif

@if ($tiles !== [])
    <p class="note legal">
        @foreach ($tiles as $tile)
            <span>{{ $R['tiles'][$tile['key']] }} : <b class="num">{{ $show($tile['type'], $tile['value']) }}</b></span>
        @endforeach
    </p>
@endif

@if ($rows->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th class="n">#</th>
            @foreach ($columns as $col)
                @if ($col['type'] === 'item' || $col['type'] === 'partner')
                    <th>{{ $R['cols']['code'] }}</th>
                    <th style="width: 100%">{{ $R['cols'][$col['type'] === 'item' ? 'designation' : $col['label']] }}</th>
                @else
                    <th @class(['n' => $numeric($col)])>{{ $R['cols'][$col['label']] }}</th>
                @endif
            @endforeach
        </tr>
        </thead>
        <tbody>
        {{-- chaque ligne d'un seul tenant ($cells), collee au bord : pas de blancs repetes des milliers de fois --}}
        @foreach ($rows as $r)
<tr><td class="n soft">{{ $loop->iteration }}</td>{{ $cells($r) }}</tr>
        @endforeach
        @if ($totals !== [] && $rows->count() === $matched)
            <tr class="sum">
                <td></td>
                @foreach ($columns as $col)
                    @if ($col['type'] === 'item' || $col['type'] === 'partner')
                        <td colspan="2">{{ __('print.count_total', ['count' => number_format($matched, 0, ',', ' ')]) }}</td>
                    @elseif ($loop->first)
                        <td>{{ __('print.count_total', ['count' => number_format($matched, 0, ',', ' ')]) }}</td>
                    @else
                        <td class="n"><span class="num">{{ array_key_exists($col['key'], $totals) ? $write($col, $totals[$col['key']]) : '' }}</span></td>
                    @endif
                @endforeach
            </tr>
        @endif
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
