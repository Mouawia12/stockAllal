@extends('layouts.print')

{{-- Les mouvements de la caisse sur papier (CashController) : solde au debut, chaque jour ses lignes et le solde du soir, solde a la fin. --}}
@php
    $Pr = __('print');
    $C = $Pr['cash'];
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $title = match ($dir) { 'in' => $C['title_in'], 'out' => $C['title_out'], default => $C['title'] };
    $period = App\Support\PrintPeriod::label($window);
    $pageTitle = $title.' — '.$period;
    $back = route('dashboard.index');
    $withIn = $dir !== 'out';
    $withOut = $dir !== 'in';
    $cols = 4 + ($several ? 1 : 0) + ($withIn ? 1 : 0) + ($withOut ? 1 : 0);
@endphp

@push('head')
<style>
    .figures { display: flex; flex-wrap: wrap; gap: 1mm 10mm; margin-top: 3.5mm; }
    .figures dt { font-size: .86em; color: var(--soft); }
    .figures dd { font-size: 1.15em; font-weight: 700; }
    .figures .strong dd { font-size: 1.4em; line-height: 1.15; }
</style>
@endpush

@section('tools')
    @include('print._period')
    @include('print._choice', ['label' => $C['show'], 'param' => 'dir', 'current' => $dir, 'options' => ['all' => $C['all'], 'in' => $C['in'], 'out' => $C['out']]])
@endsection

@section('content')
@include('print._head', ['letter' => $letter, 'title' => $title, 'sub' => [
    $period,
    $several ? __('print.scope', ['names' => $branchNames->join(__('stock.js.list_separator'))]) : ($letter?->print_header ? $letter->name : null),
]])

@if ($outdated->isNotEmpty())<p class="note small">{{ __('print.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>@endif
@if ($shown < $count)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($shown, 0, ',', ' '), 'total' => number_format($count, 0, ',', ' ')]) }}</b></p>@endif

<dl class="figures">
    <div><dt>{{ $C['opening'] }}</dt><dd class="num">{{ $fmt($opening) }}</dd></div>
    <div><dt>{{ $C['in'] }}</dt><dd class="num">{{ $fmt($in) }}</dd></div>
    <div><dt>{{ $C['out'] }}</dt><dd class="num">{{ $fmt($out) }}</dd></div>
    <div class="strong"><dt>{{ $C['closing'] }}</dt><dd class="num">{{ $fmt($closing) }}</dd></div>
</dl>

@if ($groups->isEmpty())
    <p class="empty">{{ $Pr['nothing'] }}</p>
@else
    <table class="grid">
        <thead>
        <tr>
            <th>{{ $C['time'] }}</th>
            <th>{{ $Pr['no'] }}</th>
            @if ($several)<th>{{ __('documents.col_branch') }}</th>@endif
            <th style="width: 100%">{{ $C['label'] }}</th>
            <th>{{ $C['entered_by'] }}</th>
            @if ($withIn)<th class="n">{{ $C['in'] }}</th>@endif
            @if ($withOut)<th class="n">{{ $C['out'] }}</th>@endif
        </tr>
        </thead>
        @foreach ($groups as $day)
            <tbody>
            <tr class="group">
                <th colspan="{{ $cols }}">
                    <span class="group-in">
                        <span>{{ $day['name'] }} <span class="num">{{ $day['date'] }}</span></span>
                        @if ($day['balance'] !== null)<span>{{ $C['closing'] }} <span class="num">{{ $fmt($day['balance']) }}</span></span>@endif
                    </span>
                </th>
            </tr>
            @foreach ($day['rows'] as $r)
                <tr>
                    <td class="num">{{ $r['time'] }}</td>
                    <td class="num">{{ $r['number'] }}</td>
                    @if ($several)<td>{{ $r['branch'] }}</td>@endif
                    <td><bdi>{{ $r['label'] ?? '—' }}</bdi>@if ($r['detail'] !== '') <span class="soft small"><bdi>{{ $r['detail'] }}</bdi></span>@endif</td>
                    <td class="small"><bdi>{{ $r['entered_by'] }}</bdi></td>
                    @if ($withIn)<td class="n"><span class="num">{{ $r['out'] ? '' : $fmt($r['amount']) }}</span></td>@endif
                    @if ($withOut)<td class="n"><span class="num">{{ $r['out'] ? $fmt($r['amount']) : '' }}</span></td>@endif
                </tr>
            @endforeach
            <tr>
                <td colspan="{{ $cols - ($withIn ? 1 : 0) - ($withOut ? 1 : 0) }}"></td>
                @if ($withIn)<td class="n"><b class="num">{{ $fmt($day['in']) }}</b></td>@endif
                @if ($withOut)<td class="n"><b class="num">{{ $fmt($day['out']) }}</b></td>@endif
            </tr>
            </tbody>
        @endforeach
        <tbody>
        <tr class="sum">
            <td colspan="{{ $cols - ($withIn ? 1 : 0) - ($withOut ? 1 : 0) }}">{{ $Pr['total'] }}</td>
            @if ($withIn)<td class="n"><span class="num">{{ $fmt($in) }}</span></td>@endif
            @if ($withOut)<td class="n"><span class="num">{{ $fmt($out) }}</span></td>@endif
        </tr>
        </tbody>
    </table>
@endif

@include('print._foot', ['letter' => $letter, 'by' => true])
@endsection
