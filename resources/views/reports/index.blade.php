@extends('layouts.app')

{{--
    "التقارير" de "لوحة القيادة" (ReportController) : un rapport de App\Support\Reports en entier. En haut son titre, imprimer
    et exporter ; puis les rapports du meme menu, la periode (ou les jours du stock dormant), ses chiffres, et sous la
    recherche la liste (reports/_list), remplacee pendant la frappe. Les barres sont en bleu-vert, jamais en rouge ou jaune
    (les couleurs du stock).
--}}
@php
    $R = __('reports');
    $title = $R['titles'][$report];
    $locale = app()->getLocale();
    $n0 = fn ($v) => $v === null ? '—' : number_format(round($v), 0, ',', ' ');
    $n2 = fn ($v) => $v === null ? '—' : number_format($v, 2, ',', ' ');
    $qty = fn ($v) => $v === null ? '—' : number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' ');
    // un nombre dans une phrase : isole de gauche a droite, sinon "33 230" s'affiche "230 33" en arabe
    $iso = fn (string $s) => "\u{2066}{$s}\u{2069}";
    $when = fn (string $key) => strlen($key) === 7
        ? Illuminate\Support\Carbon::parse($key.'-01')->locale($locale)->translatedFormat('F Y')
        : Illuminate\Support\Carbon::parse($key)->locale($locale)->translatedFormat('l d/m/Y');
    $show = fn (string $type, $v) => match ($type) {
        'money2' => $n2($v),
        'qty' => $qty($v),
        'pct' => $v === null ? '—' : number_format($v, 1, ',', ' '),
        'period' => $when($v),
        default => $n0($v),
    };
    // l'adresse de ce rapport avec ce qu'on garde (periode, filtre, tri, recherche) et ce qui change
    $url = fn (array $change = []) => route('dashboard.reports', ['report' => $report] + array_filter(array_merge($periodState, $listState, $change), fn ($v) => $v !== null && $v !== ''));
    $noPeriod = ['period' => null, 'day' => null, 'from' => null, 'to' => null, 'page' => null];
    $carry = array_filter($periodic ? $window['query'] : []);
    $state = $periodState + $listState;
@endphp
@section('title', ($q !== '' ? "{$q} — " : '').$title.' — '.__('common.app_name'))

@push('head')
<style>
    .rep-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px 20px; flex-wrap: wrap; margin-bottom: 12px; }
    .rep-head h1 { margin: 0; font-size: 24px; line-height: 1.2; }
    .rep-head .sub { margin: 4px 0 0; color: var(--muted); font-size: 13.5px; max-width: 760px; }
    .rep-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 13px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 13.5px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .chip .n { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); background: var(--row-hover); border-radius: 99px; padding: 0 8px; margin-inline-end: -6px; }
    .chip.on .n { background: var(--card); color: var(--accent); }
    /* les rapports du meme menu : des onglets sous le titre */
    .tabs { display: flex; gap: 2px; flex-wrap: wrap; margin: 0 0 14px; border-bottom: 1px solid var(--border); }
    .tabs a { padding: 7px 14px; color: var(--muted); border-bottom: 2px solid transparent; margin-bottom: -1px; font-weight: 600; white-space: nowrap; }
    .tabs a:hover { color: var(--text); }
    .tabs a.on { color: var(--accent); border-bottom-color: var(--accent); }
    .bar { display: flex; gap: 8px 16px; flex-wrap: wrap; align-items: center; margin: 0 0 12px; }
    .bar .label { color: var(--muted); font-size: 13.5px; }
    .bar .when { color: var(--text); font-weight: 600; font-size: 13.5px; }
    .dates { display: inline-flex; gap: 6px 10px; flex-wrap: wrap; align-items: center; margin: 0; color: var(--muted); font-size: 13.5px; }
    .dates input[type=date] { font: inherit; padding: 4px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--text); }
    .dates button { padding: 4px 12px; }
    .note { margin: 0 0 12px; }

    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 14px; }
    .tile { display: flex; flex-direction: column; gap: 2px; }
    .tile .label { margin: 0; color: var(--muted); font-size: 13.5px; font-weight: 600; }
    .tile .value { margin: 0; font-size: 30px; line-height: 1.1; }
    .tile .value.words { font-size: 19px; line-height: 1.5; font-family: var(--font); }
    .tile .sub { margin: 0; color: var(--muted); font-size: 13px; }
    .fig { font-family: var(--font-code); font-weight: 700; letter-spacing: .01em; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }

    .trend-card { margin-bottom: 14px; }
    .trend-card h2 { margin: 0 0 8px; font-size: 14px; color: var(--muted); font-weight: 600; }
    .bars { height: 130px; display: flex; align-items: flex-end; gap: 3px; border-bottom: 1px solid var(--border); }
    .bars span { flex: 1 1 0; min-width: 2px; height: var(--h); min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .bars span:hover { background: var(--accent); }
    .bars span.empty { background: var(--border); }
    .axis { display: flex; justify-content: space-between; margin-top: 5px; color: var(--muted); font-size: 11.5px; }

    .search { display: flex; gap: 8px; margin-bottom: 12px; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }

    .count { margin: 0 0 8px; }
    table.rep th.n, table.rep td.n { text-align: end; }
    table.rep td { padding-top: 7px; padding-bottom: 7px; }
    table.rep th a.sort { color: inherit; display: inline-flex; align-items: center; gap: 4px; }
    table.rep th a.sort:hover { color: var(--text); }
    table.rep th[aria-sort] a.sort { color: var(--accent); }
    table.rep th .arrow { font-size: 9px; }
    table.rep tfoot td { border-top: 2px solid var(--border); border-bottom: 0; font-weight: 700; }
    .rank { color: var(--muted); font: 600 15px/1 var(--font-code); width: 1%; }
    .item a { color: var(--text); }
    .item a:hover { color: var(--accent); }
    .item .name { display: block; font-weight: 600; line-height: 1.35; }
    .item .code { color: var(--muted); font-size: 14px; }
    /* le nom d'un article ou d'un client garde sa place : la carte defile plutot que de l'ecraser en colonne de trois lettres */
    table.rep td.item { min-width: 190px; }
    table.rep tbody tr:focus-within > td { background: var(--row-hover); }
    /* la part de la ligne dans le total : le chiffre, et une barre dessous */
    .share-cell { display: inline-block; min-width: 64px; }
    .share { display: block; height: 4px; margin-top: 4px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .share i { display: block; height: 100%; width: var(--w); background: var(--bar-strong); border-radius: 99px; }
    .where { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
    /* la classe ABC : A plein, B leger, C neutre ; jamais rouge ni jaune */
    .abc { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 6px; font: 700 13px/1 var(--font-code); background: var(--row-hover); color: var(--muted); }
    .abc.a { background: var(--accent); color: var(--on-accent); }
    .abc.b { background: var(--accent-soft); color: var(--accent); }
    .strong { font-weight: 700; }
    .pager { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 12px; }
    .empty { padding: 26px; text-align: center; color: var(--muted); }

    @media print {
        .tabs, .bar, .rep-actions, .pager, .filters { display: none !important; }
        .card { break-inside: auto; }
    }
    @media (max-width: 700px) {
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .tile .value { font-size: 24px; }
        .tabs { flex-wrap: nowrap; overflow-x: auto; }
        table.rep td.item { min-width: 0; }
        .bars { height: 100px; gap: 2px; }
    }
</style>
@endpush

@push('keys')
    @if ($searchable)
        @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('common.keys.search')])
        @include('partials.key', ['keys' => ['↑', '↓'], 'label' => __('common.keys.move')])
    @endif
    @if ($periodic)
        @include('partials.key', ['keys' => ['Alt', '1…5'], 'label' => $R['period']])
    @endif
    @include('partials.key', ['keys' => ['Alt', 'P'], 'run' => 'Alt+P', 'label' => __('print.print')])
    @include('partials.key', ['keys' => ['Alt', 'X'], 'run' => 'Alt+X', 'label' => $R['export']])
@endpush

@section('content')
<div class="rep-head">
    <div>
        <h1>{{ $title }}</h1>
        <p class="sub">{{ $R['help'][$report] }}</p>
    </div>
    <div class="rep-actions">
        @include('partials.print-button', ['href' => route('dashboard.reports.print', ['report' => $report] + $state)])
        <a class="btn print-btn" href="{{ route('dashboard.reports.export', ['report' => $report] + $state) }}" data-key="Alt+X" title="{{ $R['export'] }} (Alt+X)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/></svg>{{ $R['export'] }}</a>
    </div>
</div>

@if (count($tabs) > 1)
    <nav class="tabs" aria-label="{{ $R['groups'][$group] }}">
        @foreach ($tabs as $tab)
            <a href="{{ route('dashboard.reports', ['report' => $tab] + $carry) }}" @class(['on' => $tab === $report]) @if ($tab === $report) aria-current="page" @endif>{{ $R['tabs'][$tab] }}</a>
        @endforeach
    </nav>
@endif

<div class="bar">
    @if ($periodic)
        <nav class="chips" aria-label="{{ $R['period'] }}">
            @foreach (App\Support\Reports::PERIODS as $p)
                <a href="{{ $url(['period' => $p === '30' ? null : $p] + $noPeriod) }}" data-key="Alt+{{ $loop->iteration }}"
                   @class(['chip', 'on' => $window['key'] === $p]) @if ($window['key'] === $p) aria-current="true" @endif>{{ $R['periods'][$p] }}</a>
            @endforeach
        </nav>
        <form class="dates" method="get" action="{{ route('dashboard.reports', ['report' => $report]) }}">
            @foreach (array_diff_key($state, $noPeriod) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label>{{ $R['from'] }} <input type="date" name="from" value="{{ $window['from'] }}" max="{{ $today }}"></label>
            <label>{{ $R['to'] }} <input type="date" name="to" value="{{ $window['to'] }}" max="{{ $today }}"></label>
            <button type="submit">{{ $R['apply'] }}</button>
        </form>
        @if ($report === 'days')
            <nav class="chips" aria-label="{{ $R['unit'] }}">
                @foreach (['day', 'month'] as $u)
                    <a href="{{ $url(['unit' => $u, 'page' => null]) }}" @class(['chip', 'on' => $unit === $u]) @if ($unit === $u) aria-current="true" @endif>{{ $R['units'][$u] }}</a>
                @endforeach
            </nav>
        @endif
    @elseif ($report === 'dormant')
        <span class="label">{{ $R['dormant_days'] }}</span>
        <nav class="chips" aria-label="{{ $R['dormant_days'] }}">
            @foreach (App\Support\Reports::DORMANT_DAYS as $d)
                <a href="{{ $url(['days' => $d === App\Support\SalesDashboard::DORMANT_DAYS ? null : $d, 'page' => null]) }}" data-key="Alt+{{ $loop->iteration }}"
                   @class(['chip', 'on' => $days === $d]) @if ($days === $d) aria-current="true" @endif>{{ __('reports.days_n', ['count' => $d]) }}</a>
            @endforeach
        </nav>
    @else
        <span class="label">{{ $R['now'] }}</span>
    @endif
</div>

<p class="note muted small">
    {{ $selected ? $selected->name : __('common.scope.all').' ('.trans_choice('dashboard.scope_all', $branches, ['count' => $branches]).')' }}
    @if ($periodic) · <span class="when">{{ $windowLabel }}</span>@endif
</p>
@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('documents.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

@if ($tiles !== [])
    <div class="tiles">
        @foreach ($tiles as $tile)
            <section class="card tile">
                <h2 class="label">{{ $R['tiles'][$tile['key']] }}</h2>
                <p @class(['value', 'fig' => $tile['type'] !== 'period', 'words' => $tile['type'] === 'period'])>{{ $show($tile['type'], $tile['value']) }}</p>
                @if ($tile['note'] && $tile['note']['value'] !== null)
                    <p class="sub">{{ __('reports.notes.'.$tile['note']['key'], ['v' => $iso($show($tile['note']['type'], $tile['note']['value']))]) }}</p>
                @endif
            </section>
        @endforeach
    </div>
@endif

@if ($chart)
    <section class="card trend-card">
        <h2>{{ $R['chart'][$chart['metric']] }}</h2>
        <div class="bars" role="img" aria-label="{{ $R['chart'][$chart['metric']] }}">
            @foreach ($chart['points'] as $p)
                <span @class(['empty' => $p[$chart['metric']] <= 0]) style="--h: {{ round(max(0, $p[$chart['metric']]) / $chart['max'] * 100, 1) }}%"
                      title="{{ $when($p['day']) }} — {{ $iso($n0($p[$chart['metric']])) }}"></span>
            @endforeach
        </div>
        <div class="axis" aria-hidden="true"><span>{{ $when($chart['points']->first()['day']) }}</span><span>{{ $when($chart['points']->last()['day']) }}</span></div>
    </section>
@endif

@if ($searchable)
    <form class="search" method="get" action="{{ route('dashboard.reports', ['report' => $report]) }}" data-live="results" data-title="{{ $title.' — '.__('common.app_name') }}">
        <div class="q-wrap">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ $R['placeholder'][in_array($report, ['customers', 'suppliers', 'receivable', 'payable'], true) ? 'partner' : 'item'] }}"
                   autocomplete="off" aria-controls="results" data-key="F3">
            <span class="live-spin" aria-hidden="true"></span>
        </div>
        @foreach (array_diff_key($state, ['q' => null]) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button class="primary" type="submit">{{ $R['search'] }}</button>
    </form>
@endif

{{-- filtres, liste et pages : remplaces pendant la frappe (reports/_list, ?partial=1) --}}
<div id="results" data-live-results>
    @include('reports._list')
</div>
@if ($searchable)
    @include('partials.live-search')
@endif
@endsection

@push('scripts')
<script>
(() => {
    // fleches d'une ligne a l'autre (depuis la recherche aussi), Entree ouvre l'article ou la fiche, Echap revient a la
    // recherche. L'ecouteur est sur #results : la liste y est remplacee pendant la frappe.
    const input = document.querySelector('form.search input[name=q]');
    const results = document.getElementById('results');
    const links = () => [...results.querySelectorAll('table.rep tbody td.item a')];
    // imprimer et exporter ce qu'on voit : apres une recherche pendant la frappe, leurs liens prennent le mot cherche
    results.addEventListener('results:updated', () => {
        const q = input.value.trim();
        document.querySelectorAll('.rep-actions a').forEach(a => {
            const url = new URL(a.href);
            if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
            a.href = url;
        });
    });
    input?.addEventListener('keydown', e => {
        if (e.key !== 'ArrowDown') return;
        const first = links()[0];
        if (first) { e.preventDefault(); first.focus(); }
    });
    results.addEventListener('keydown', e => {
        const link = e.target.closest('td.item a');
        if (!link) return;
        if (e.key === 'Escape' && input) { e.preventDefault(); input.focus(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        const list = links();
        const i = list.indexOf(link) + (e.key === 'ArrowDown' ? 1 : -1);
        if (i < 0) input?.focus();
        else list[Math.min(i, list.length - 1)].focus();
    });
})();
</script>
@endpush
