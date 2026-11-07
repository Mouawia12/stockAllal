@extends('layouts.print')

{{--
    Le releve d'un client ou d'un fournisseur sur papier (PartnerController::printCard) : ses bons et ses versements par
    date, ou seulement ses bons, ses versements ou ses articles (?show=). Le solde actuel (tout l'historique, comme
    Gescomm : PartnerController::balance) avec les chiffres, quelle que soit la periode imprimee.
--}}
@php
    $P = __('partners');
    $Pr = __('print');
    $S = $Pr['statement'];
    $fmt = fn ($n) => $n === null ? '' : number_format($n, 2, ',', ' ');
    $qty = fn ($n) => $n === null ? '' : number_format($n, fmod((float) $n, 1) == 0 ? 0 : 2, ',', ' ');
    $title = match ($show) {
        'bons' => $S['title_bons'][$kind],
        'payments' => $S['title_payments'][$kind],
        'items' => $S['title_items'][$kind],
        default => $S['title'][$kind],
    };
    $period = App\Support\PrintPeriod::label($window);
    $pageTitle = $title.' — '.($partner->name ?? $partner->code);
    $back = route("{$route}.show", $partner);
    $phones = collect([$partner->phone, $partner->mobile])->filter()->join(' / ');
    $legal = collect(['rc', 'nif', 'nis', 'ai'])->filter(fn ($f) => $partner->{$f})->map(fn ($f) => $Pr['bon'][$f].' : '.$partner->{$f});
    $withBons = in_array($show, ['all', 'bons'], true);
    $withPaid = in_array($show, ['all', 'payments'], true);
@endphp

@push('head')
<style>
    .party { margin-top: 3.5mm; }
    .party-name { font-size: 1.3em; font-weight: 700; line-height: 1.25; }
    .figures { display: flex; flex-wrap: wrap; gap: 1mm 10mm; margin-top: 3mm; }
    .figures dt { font-size: .86em; color: var(--soft); }
    .figures dd { font-size: 1.15em; font-weight: 700; }
</style>
@endpush

@section('tools')
    @include('print._period')
    @include('print._choice', ['label' => $S['show'], 'param' => 'show', 'current' => $show, 'options' => [
        'all' => $S['show_all'], 'bons' => $S['show_bons'], 'payments' => $S['show_payments'], 'items' => $S['show_items'],
    ]])
@endsection

@section('content')
@include('print._head', ['letter' => $branch, 'title' => $title, 'sub' => [$period, $branch->print_header ? $branch->name : null]])

<section class="party">
    <p class="party-name"><bdi>{{ $partner->name ?? $partner->code }}</bdi> <span class="code soft ltr">{{ $partner->code }}</span>@if ($partner->blocked) <span class="tag">{{ $P['blocked'] }}</span>@endif</p>
    @if ($partner->address || $partner->wilaya)<p><bdi>{{ collect([$partner->address, $partner->wilaya])->filter()->join(' — ') }}</bdi></p>@endif
    @if ($phones !== '')<p class="num">{{ $phones }}</p>@endif
    @if ($legal->isNotEmpty())<p class="legal ltr">@foreach ($legal as $id)<span>{{ $id }}</span>@endforeach</p>@endif
</section>

@if ($capped)<p class="note"><b>{{ __('print.capped', ['shown' => number_format($moves->count(), 0, ',', ' '), 'total' => number_format($stats['bons'] + $stats['payments'], 0, ',', ' ')]) }}</b></p>@endif

@if ($show === 'items')
    @if ($items->isEmpty())
        <p class="empty">{{ $Pr['nothing'] }}</p>
    @else
        <table class="grid">
            <thead>
            <tr>
                <th>{{ __('documents.col_code') }}</th>
                <th style="width: 100%">{{ __('documents.col_designation') }}</th>
                <th class="n">{{ __('documents.col_lines') }}</th>
                <th class="n">{{ $S['net'] }}</th>
                @if ($money)<th class="n">{{ __('documents.col_amount') }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($items as $i)
                <tr>
                    <td style="white-space: nowrap"><span class="code ltr">{{ $i['code'] }}</span></td>
                    <td><bdi>{{ $i['designation'] ?? '—' }}</bdi></td>
                    <td class="n"><span class="num">{{ $i['lines'] }}</span></td>
                    <td class="n"><span class="num">{{ $qty($i['qty']) }}</span></td>
                    @if ($money)<td class="n"><span class="num">{{ $fmt($i['amount']) }}</span></td>@endif
                </tr>
            @endforeach
            <tr class="sum">
                <td colspan="3">{{ __('print.count_total', ['count' => number_format($items->count(), 0, ',', ' ')]) }}</td>
                <td class="n"><span class="num">{{ $qty($items->sum('qty')) }}</span></td>
                @if ($money)<td class="n"><span class="num">{{ $fmt($items->sum('amount')) }}</span></td>@endif
            </tr>
            </tbody>
        </table>
    @endif
@else
    @if ($money)
        <dl class="figures">
            @if ($withBons)<div><dt>{{ $S['bons'] }} ({{ number_format($stats['bons'], 0, ',', ' ') }})</dt><dd class="num">{{ $fmt($stats['amount']) }}</dd></div>@endif
            @if ($withPaid)
                <div><dt>{{ $S['payments'] }} ({{ number_format($stats['payments'], 0, ',', ' ') }})</dt><dd class="num">{{ $fmt($stats['paid']) }}</dd></div>
                @foreach ($paidByType as $t)
                    <div><dt><bdi>{{ $t['type'] ?? '—' }}</bdi> ({{ $t['n'] }})</dt><dd class="num">{{ $fmt($t['amount']) }}</dd></div>
                @endforeach
            @endif
            @if ($balance)
                <div><dt>{{ $P['balance_now'] }} ({{ $P['balance_side'][$kind][$balance['value'] > 0.005 ? 'pos' : ($balance['value'] < -0.005 ? 'neg' : 'zero')] }})</dt><dd class="num">{{ $fmt($balance['value']) }}</dd></div>
            @endif
        </dl>
    @endif

    @if ($moves->isEmpty())
        <p class="empty">{{ $Pr['nothing'] }}</p>
    @else
        <table class="grid">
            <thead>
            <tr>
                <th>{{ $S['date'] }}</th>
                <th>{{ $S['move'] }}</th>
                <th>{{ $Pr['no'] }}</th>
                <th style="width: 100%">{{ $S['note'] }}</th>
                @if ($money)
                    @if ($withBons)<th class="n">{{ $S['bons'] }}</th>@endif
                    @if ($withPaid)<th class="n">{{ $S['payments'] }}</th>@endif
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($moves as $m)
                <tr>
                    <td style="white-space: nowrap"><span class="num">{{ $m['date'] }}</span> <span class="num soft small">{{ $m['time'] }}</span></td>
                    <td style="white-space: nowrap">{{ $m['bon'] ? $S['bon'] : $S['payment'] }}@if ($m['type']) <bdi class="soft">{{ $m['type'] }}</bdi>@endif</td>
                    <td><span class="code ltr">{{ $m['number'] }}</span></td>
                    <td class="small"><bdi>{{ $m['note'] }}</bdi></td>
                    @if ($money)
                        @if ($withBons)<td class="n"><span class="num">{{ $fmt($m['billed']) }}</span></td>@endif
                        @if ($withPaid)<td class="n"><span class="num">{{ $fmt($m['paid']) }}</span></td>@endif
                    @endif
                </tr>
            @endforeach
            @if ($money)
                <tr class="sum">
                    <td colspan="4">{{ $Pr['total'] }}</td>
                    @if ($withBons)<td class="n"><span class="num">{{ $fmt($stats['amount']) }}</span></td>@endif
                    @if ($withPaid)<td class="n"><span class="num">{{ $fmt($stats['paid']) }}</span></td>@endif
                </tr>
            @endif
            </tbody>
        </table>
    @endif
@endif

@include('print._foot', ['letter' => $branch])
@endsection
