@extends('layouts.app')

{{--
    Clients ou fournisseurs (PartnerController) : recherche (nom, code, telephone), periode, filtres actifs / bloques,
    puis chacun avec ses bons et son montant sur la periode et la date de son dernier bon. Les plus gros d'abord.
--}}
@php
    $P = __('partners');
    $title = $P['title'][$kind];
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
    .print-drop { margin-inline-start: auto; }
    .print-drop .drop-menu a { padding: 8px 10px; border-radius: 8px; color: var(--text); }
    .print-drop .drop-menu a:hover, .print-drop .drop-menu a:focus-visible { background: var(--row-hover); }
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
    @include('partials.key', ['keys' => ['Alt', 'P'], 'run' => 'Alt+P', 'label' => __('print.print')])
@endpush

@section('content')
<h1 class="page-title">{{ $title }}</h1>

<form class="search" method="get" action="{{ route("{$route}.index") }}" data-live="results" data-title="{{ $title.' — '.__('common.app_name') }}">
    <div class="q-wrap">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ $P['placeholder'][$kind] }}" autocomplete="off" aria-controls="results" data-key="F3" @if ($q === '') autofocus @endif>
        <span class="live-spin" aria-hidden="true"></span>
    </div>
    @if ($period !== '365')<input type="hidden" name="period" value="{{ $period }}">@endif
    @if ($filter !== 'all')<input type="hidden" name="f" value="{{ $filter }}">@endif
    <button class="primary" type="submit">{{ $P['search'] }}</button>
</form>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('partners.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

{{-- filtres, liste et pages : remplaces pendant la frappe (partners/_list, ?partial=1) --}}
<div id="results" data-live-results>
    @include('partners._list')
</div>
@include('partials.live-search')
@endsection

@push('scripts')
<script>
(() => {
    // fleches d'un nom a l'autre (depuis la recherche aussi), Entree ouvre la fiche, Echap revient a la recherche.
    // L'ecouteur est sur #results : la liste y est remplacee pendant la frappe.
    const input = document.querySelector('form.search input[name=q]');
    const results = document.getElementById('results');
    const links = () => [...results.querySelectorAll('table.people a.who')];
    input.addEventListener('keydown', e => {
        if (e.key !== 'ArrowDown') return;
        const first = links()[0];
        if (first) { e.preventDefault(); first.focus(); }
    });
    results.addEventListener('keydown', e => {
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
