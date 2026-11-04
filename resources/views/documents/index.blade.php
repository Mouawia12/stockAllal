@extends('layouts.app')

{{--
    Bons de vente ou d'achat (DocumentController), comme la "Consultation des Ventes" de Gescomm : recherche (numero,
    client), periode, filtre "avec retour", puis les bons jour par jour avec le nombre et le montant de chaque jour.
    Un retour est une ligne negative dans un bon : marque "مرتجع", jamais en jaune (le jaune = stock negatif).
--}}
@php
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $title = $D['title'][$kind];
    $cols = 4 + ($several ? 1 : 0) + ($money ? 1 : 0) + 1;
@endphp
@section('title', ($q !== '' ? "{$q} — " : '').$title.' — '.__('common.app_name'))

@push('head')
<style>
    .page-title { font-size: 20px; margin: 0 0 12px; }
    .search { display: flex; gap: 8px; margin-bottom: 12px; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }
    .bar { display: flex; gap: 8px 16px; flex-wrap: wrap; align-items: center; margin: 0 0 12px; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 12px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 14px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip .n { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); background: var(--row-hover); border-radius: 99px; padding: 0 8px; margin-inline-end: -6px; }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .chip.on .n { background: var(--card); color: var(--accent); }
    .sum { display: flex; gap: 6px 22px; flex-wrap: wrap; margin-inline-start: auto; color: var(--muted); font-size: 14px; }
    .sum b { color: var(--text); font-variant-numeric: tabular-nums; }
    .note { margin: 0 0 12px; }

    .bons th.n, .bons td.n { text-align: end; white-space: nowrap; }
    /* un jour = son nom, sa date, puis a la fin son nombre de bons et son montant (le "groupement" d'Odoo) */
    .bons tr.day th { background: var(--bg); color: var(--text); font-size: 14px; padding-top: 14px; border-bottom: 1px solid var(--border); }
    .bons tr.day .when { display: flex; align-items: baseline; gap: 10px; }
    .bons tr.day .date { color: var(--muted); font-weight: 500; }
    .bons tr.day .grow { flex: 1; }
    .bons tr.day .day-sum { font-weight: 600; font-variant-numeric: tabular-nums; }
    .bons tbody:first-of-type tr.day th { padding-top: 8px; }
    a.bon { font-family: var(--font-code); font-weight: 600; font-size: 17px; color: var(--text); letter-spacing: .02em; }
    a.bon:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    a.who { color: inherit; }
    a.who:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .bons tbody tr:focus-within > td { background: var(--row-hover); }
    .bons td.neg-amount { color: var(--muted); }
    .pager { display: flex; justify-content: space-between; gap: 8px; margin-top: 12px; }
    .empty { padding: 26px; text-align: center; color: var(--muted); }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('common.keys.search')])
    @include('partials.key', ['keys' => ['↑', '↓'], 'label' => __('common.keys.move')])
    @include('partials.key', ['keys' => [__('common.keys.enter')], 'label' => $D['keys']['open']])
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => $D['period']])
@endpush

@section('content')
<h1 class="page-title">{{ $title }}</h1>

<form class="search" method="get" action="{{ route("{$route}.index") }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="{{ $D['placeholder'][$kind] }}" autocomplete="off" data-key="F3" @if ($q === '') autofocus @endif>
    @if ($period !== '30')<input type="hidden" name="period" value="{{ $period }}">@endif
    @if ($filter !== 'all')<input type="hidden" name="f" value="{{ $filter }}">@endif
    <button class="primary" type="submit">{{ $D['search'] }}</button>
</form>

<div class="bar">
    <nav class="chips" aria-label="{{ $D['period'] }}">
        @foreach (\App\Http\Controllers\DocumentController::PERIODS as $key => $days)
            <a href="{{ route("{$route}.index", array_filter(['q' => $q, 'period' => (string) $key === '30' ? null : $key, 'f' => $filter === 'all' ? null : $filter])) }}"
               data-key="Alt+{{ $loop->iteration }}" @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ $D['periods'][$key] }}</a>
        @endforeach
    </nav>
    <nav class="chips" aria-label="{{ $D['filter'] }}">
        @foreach (\App\Http\Controllers\DocumentController::FILTERS as $f)
            <a href="{{ route("{$route}.index", array_filter(['q' => $q, 'period' => $period === '30' ? null : $period, 'f' => $f === 'all' ? null : $f])) }}"
               @class(['chip', 'on' => $filter === $f]) @if ($filter === $f) aria-current="true" @endif>{{ $D['filters'][$f] }} <span class="n">{{ number_format($counts[$f], 0, ',', ' ') }}</span></a>
        @endforeach
    </nav>
    @if ($money && $counts[$filter] > 0)
        <p class="sum">{{ $D['stat_amount'] }} <b class="num">{{ $fmt($total) }}</b></p>
    @endif
</div>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('documents.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

<div class="card">
    @if ($branches === 0)
        <p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : $D['no_branch'] }}</p>
    @elseif ($groups->isEmpty())
        <p class="empty">{{ $q !== '' ? __('documents.none_search', ['q' => $q]) : $D['none'][$kind] }}</p>
    @else
        <table class="bons">
            <thead>
            <tr>
                <th scope="col">{{ $D['col_bon'] }}</th>
                <th scope="col" class="hide-sm">{{ $D['col_time'] }}</th>
                @if ($several)<th scope="col">{{ $D['col_branch'] }}</th>@endif
                <th scope="col">{{ $D['col_partner'][$kind] }}</th>
                <th scope="col" class="n">{{ $D['col_lines'] }}</th>
                @if ($money)<th scope="col" class="n">{{ $D['col_amount'] }}</th>@endif
                <th scope="col" class="hide-sm">{{ $kind === 'sale' ? $D['col_served_by'] : $D['col_payment'] }}</th>
            </tr>
            </thead>
            @foreach ($groups as $day)
                <tbody>
                <tr class="day">
                    <th scope="rowgroup" colspan="{{ $cols }}">
                        <span class="when">
                            <span>{{ $day['label']['name'] }}</span>
                            <span class="date num">{{ $day['label']['date'] }}</span>
                            <span class="grow"></span>
                            <span class="muted small">{{ trans_choice('documents.bons', $day['bons'], ['count' => $day['bons']]) }}</span>
                            @if ($money)<span class="day-sum num">{{ $fmt($day['amount']) }}</span>@endif
                        </span>
                    </th>
                </tr>
                @foreach ($day['rows'] as $r)
                    <tr @class(['bt' => $several]) @if ($several) style="--bh: {{ $r['hue'] }}" @endif>
                        <td><a class="bon ltr" href="{{ $r['url'] }}">{{ $r['number'] }}</a></td>
                        <td class="num muted hide-sm">{{ $r['time'] }}</td>
                        @if ($several)<td>{{ $r['branch'] }}</td>@endif
                        <td>@if ($r['partner_url'])<a class="who" href="{{ $r['partner_url'] }}"><bdi>{{ $r['partner'] }}</bdi></a>@else<bdi>{{ $r['partner'] ?? '—' }}</bdi>@endif @if ($r['partner_code']) <span class="muted small ltr hide-sm">{{ $r['partner_code'] }}</span>@endif</td>
                        <td class="n">
                            @if ($r['returns'] > 0)<span class="pill" title="{{ trans_choice('documents.return_lines', $r['returns'], ['count' => $r['returns']]) }}">{{ $D['return'] }}</span>@endif
                            <span class="num">{{ $r['lines'] }}</span>
                        </td>
                        @if ($money)<td class="n"><span class="num">{{ $fmt($r['amount']) }}</span></td>@endif
                        <td class="hide-sm muted"><bdi>{{ $kind === 'sale' ? $r['served_by'] : $r['payment'] }}</bdi></td>
                    </tr>
                @endforeach
                </tbody>
            @endforeach
        </table>
        @if ($documents->hasPages())
            <nav class="pager" aria-label="{{ $D['pages'] }}">
                @if ($documents->previousPageUrl())<a class="btn" href="{{ $documents->previousPageUrl() }}">{{ $D['newer'] }}</a>@else<span></span>@endif
                @if ($documents->nextPageUrl())<a class="btn" href="{{ $documents->nextPageUrl() }}">{{ $D['older'] }}</a>@endif
            </nav>
        @endif
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    // clavier facon Gescomm : fleches d'un bon a l'autre (depuis la recherche aussi), Entree ouvre le bon (le lien),
    // Echap revient a la recherche
    const input = document.querySelector('form.search input[name=q]');
    const bons = () => [...document.querySelectorAll('table.bons a.bon')];
    input.addEventListener('keydown', e => {
        if (e.key !== 'ArrowDown') return;
        const first = bons()[0];
        if (first) { e.preventDefault(); first.focus(); }
    });
    document.querySelector('table.bons')?.addEventListener('keydown', e => {
        const link = e.target.closest('a.bon');
        if (!link) return;
        if (e.key === 'Escape') { e.preventDefault(); input.focus(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        const list = bons();
        const i = list.indexOf(link) + (e.key === 'ArrowDown' ? 1 : -1);
        if (i < 0) input.focus();
        else list[Math.min(i, list.length - 1)].focus();
    });
})();
</script>
@endpush
