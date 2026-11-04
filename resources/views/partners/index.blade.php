@extends('layouts.app')

{{--
    Clients ou fournisseurs (PartnerController) : recherche (nom, code, telephone), periode, filtres actifs / bloques,
    puis chacun avec ses bons et son montant sur la periode et la date de son dernier bon. Les plus gros d'abord.
--}}
@php
    $P = __('partners');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $title = $P['title'][$kind];
    $keep = fn (array $change) => array_filter(array_merge(['q' => $q, 'period' => $period === '365' ? null : $period, 'f' => $filter === 'all' ? null : $filter], $change));
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
    .note { margin: 0 0 12px; }
    .people th.n, .people td.n { text-align: end; white-space: nowrap; }
    a.who { color: var(--text); font-weight: 600; }
    a.who:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    /* l'ecart a la fin du nom : le code est en ltr, sa propre marge "start" tomberait du mauvais cote en arabe */
    .people a.who { margin-inline-end: 6px; }
    .people .code { font-size: 14px; color: var(--muted); }
    .people tbody tr:focus-within > td { background: var(--row-hover); }
    .pager { display: flex; justify-content: space-between; gap: 8px; margin-top: 12px; }
    .empty { padding: 26px; text-align: center; color: var(--muted); }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('common.keys.search')])
    @include('partials.key', ['keys' => ['↑', '↓'], 'label' => __('common.keys.move')])
    @include('partials.key', ['keys' => [__('common.keys.enter')], 'label' => $P['keys']['open'][$kind]])
    @include('partials.key', ['keys' => ['Alt', '1…3'], 'label' => $P['period']])
@endpush

@section('content')
<h1 class="page-title">{{ $title }}</h1>

<form class="search" method="get" action="{{ route("{$route}.index") }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="{{ $P['placeholder'][$kind] }}" autocomplete="off" data-key="F3" @if ($q === '') autofocus @endif>
    @if ($period !== '365')<input type="hidden" name="period" value="{{ $period }}">@endif
    @if ($filter !== 'all')<input type="hidden" name="f" value="{{ $filter }}">@endif
    <button class="primary" type="submit">{{ $P['search'] }}</button>
</form>

<div class="bar">
    <nav class="chips" aria-label="{{ $P['period'] }}">
        @foreach (\App\Http\Controllers\PartnerController::LIST_PERIODS as $key => $days)
            <a href="{{ route("{$route}.index", $keep(['period' => (string) $key === '365' ? null : $key])) }}" data-key="Alt+{{ $loop->iteration }}"
               @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ $P['periods'][$key] }}</a>
        @endforeach
    </nav>
    <nav class="chips" aria-label="{{ $P['filter'] }}">
        @foreach (\App\Http\Controllers\PartnerController::FILTERS as $f)
            <a href="{{ route("{$route}.index", $keep(['f' => $f === 'all' ? null : $f])) }}"
               @class(['chip', 'on' => $filter === $f]) @if ($filter === $f) aria-current="true" @endif>{{ $P['filters'][$kind][$f] }} <span class="n">{{ number_format($counts[$f], 0, ',', ' ') }}</span></a>
        @endforeach
    </nav>
</div>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('partners.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

<div class="card">
    @if ($branches === 0)
        <p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : __('documents.no_branch') }}</p>
    @elseif ($rows->isEmpty())
        <p class="empty">{{ $q !== '' ? __('partners.none_search', ['q' => $q]) : $P['none'][$kind] }}</p>
    @else
        <table class="people">
            <thead>
            <tr>
                <th scope="col">{{ $P['col_name'][$kind] }}</th>
                @if ($several)<th scope="col">{{ __('documents.col_branch') }}</th>@endif
                <th scope="col" class="hide-sm">{{ $P['col_phone'] }}</th>
                <th scope="col" class="hide-sm">{{ $P['col_wilaya'] }}</th>
                <th scope="col" class="n">{{ $P['col_bons'] }}</th>
                @if ($money)<th scope="col" class="n">{{ $P['col_amount'][$kind] }}</th>@endif
                <th scope="col" class="n hide-sm">{{ $P['col_last'] }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                <tr @class(['bt' => $several]) @if ($several) style="--bh: {{ $r['hue'] }}" @endif>
                    <td>
                        <a class="who" href="{{ $r['url'] }}"><bdi>{{ $r['name'] ?? $r['code'] }}</bdi></a><span class="code ltr">{{ $r['code'] }}</span>
                        @if ($r['blocked'])<span class="pill" title="{{ $P['blocked_help'] }}">{{ $P['blocked'] }}</span>@endif
                    </td>
                    @if ($several)<td>{{ $r['branch'] }}</td>@endif
                    <td class="hide-sm num muted">{{ $r['phone'] }}</td>
                    <td class="hide-sm muted"><bdi>{{ $r['wilaya'] }}</bdi></td>
                    <td class="n"><span class="num">{{ $r['bons'] ?: '' }}</span></td>
                    @if ($money)<td class="n"><span class="num">{{ $r['bons'] ? $fmt($r['amount']) : '' }}</span></td>@endif
                    <td class="n hide-sm muted"><span class="num">{{ $r['last'] }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($partners->hasPages())
            <nav class="pager" aria-label="{{ __('documents.pages') }}">
                @if ($partners->previousPageUrl())<a class="btn" href="{{ $partners->previousPageUrl() }}">{{ $P['previous'] }}</a>@else<span></span>@endif
                @if ($partners->nextPageUrl())<a class="btn" href="{{ $partners->nextPageUrl() }}">{{ $P['next'] }}</a>@endif
            </nav>
        @endif
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    // fleches d'un nom a l'autre (depuis la recherche aussi), Entree ouvre la fiche, Echap revient a la recherche
    const input = document.querySelector('form.search input[name=q]');
    const links = () => [...document.querySelectorAll('table.people a.who')];
    input.addEventListener('keydown', e => {
        if (e.key !== 'ArrowDown') return;
        const first = links()[0];
        if (first) { e.preventDefault(); first.focus(); }
    });
    document.querySelector('table.people')?.addEventListener('keydown', e => {
        const link = e.target.closest('a.who');
        if (!link) return;
        if (e.key === 'Escape') { e.preventDefault(); input.focus(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        const list = links();
        const i = list.indexOf(link) + (e.key === 'ArrowDown' ? 1 : -1);
        if (i < 0) input.focus();
        else list[Math.min(i, list.length - 1)].focus();
    });
})();
</script>
@endpush
