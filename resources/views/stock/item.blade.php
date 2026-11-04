@extends('layouts.app')

{{--
    Page d'un article (route stock.item) : en haut l'etiquette de rayon (code, designation, quantite, prix de detail
    de la branche principale), le seul element fort de la page ; dessous le "rayon" (stock/_shelf), une ligne par
    branche affichee (App\Support\BranchScope) ; puis, pour qui a sales.view, ses ventes et achats (stock/_activity).
--}}
@php
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $others = array_values(array_diff($codes, [$code]));
    $retail = $show_sale ? ($main_prices['price'] ?? null) : null;
@endphp
@section('title', $code.' — '.__('common.app_name'))

@push('head')
<style>
    .crumbs { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; color: var(--muted); font-size: 14px; }
    .crumbs a { color: var(--muted); }
    .crumbs a:hover { color: var(--accent); }
    .item-head { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 16px; margin-bottom: 16px; }

    /* l'etiquette de rayon : jaune, un trou de crochet, le code en grand comme sur les etiquettes du depot */
    .tag { position: relative; background: var(--tag); color: var(--tag-ink); border-radius: 8px; padding: 22px 26px 20px; padding-inline-start: 58px;
        box-shadow: inset 0 -5px 0 var(--tag-edge), var(--shadow); }
    .tag-hole { position: absolute; inset-inline-start: 22px; top: 28px; width: 16px; height: 16px; border-radius: 50%; background: var(--bg);
        box-shadow: inset 0 2px 3px rgba(0, 0, 0, .35); }
    .tag-code { font: 700 clamp(44px, 7vw, 68px)/.95 var(--font-code); letter-spacing: .01em; margin: 0; overflow-wrap: anywhere; }
    .tag-merged { margin: 6px 0 0; font: 600 18px/1.2 var(--font-code); opacity: .72; }
    .tag-desig { margin: 10px 0 0; font-size: 16px; font-weight: 600; line-height: 1.45; }
    .tag-foot { display: flex; gap: 32px; flex-wrap: wrap; align-items: flex-end; margin-top: 22px; padding-top: 14px; border-top: 2px dashed rgba(0, 0, 0, .25); }
    .tag-num { display: block; font: 700 38px/1 var(--font-code); direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; }
    .tag-unit { display: block; margin-top: 5px; font-size: 13px; font-weight: 600; opacity: .78; }
    .tag-flag { display: inline-block; margin-inline-start: 8px; padding: 1px 9px; border-radius: 99px; background: var(--tag-ink); color: var(--tag); font-size: 12px; font-weight: 600; vertical-align: middle; }

    .facts { display: flex; flex-direction: column; gap: 14px; }
    .facts dl { margin: 0; display: grid; gap: 10px; }
    .facts dl div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
    .facts dt { color: var(--muted); }
    .facts dd { margin: 0; font-weight: 600; text-align: end; }
    .facts-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto; }
    .facts-actions .btn { display: inline-flex; align-items: center; }
    #refresh-note { margin: 0; }
    #refresh-note:empty { display: none; }

    .shelf-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
    .shelf-head h2 { font-size: 16px; margin: 0; flex: 1; }
    .legend { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; font-size: 12.5px; color: var(--muted); }
    .legend .qty { margin-inline-end: 4px; }
    #shelf[aria-busy="true"] { opacity: .7; }
    .shelf-table td { vertical-align: middle; }
    .shelf-table tr.none td { color: var(--muted); }
    .meter-cell { min-width: 190px; width: 40%; }
    .meter { display: flex; align-items: center; gap: 12px; }
    .meter-track { flex: 1; height: 10px; min-width: 60px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .meter-track span { display: block; height: 100%; inline-size: var(--w); background: var(--bar-strong); border-radius: 99px; }
    .meter .qty { min-width: 78px; text-align: end; }

    /* ventes et achats : deux registres comme la Fiche de Stock, puis les lignes */
    .activity { margin-top: 16px; }
    .act-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .act-head h2 { font-size: 16px; margin: 0; flex: 1; }
    .periods { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; padding: 3px 12px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 13.5px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .act-note { margin: 0 0 12px; padding: 8px 12px; border-radius: 8px; background: var(--warn-bg); color: var(--warn); font-size: 14px; }
    .ledgers { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 28px; }
    .ledger caption, .by-branch caption { text-align: start; font-weight: 600; padding: 0 0 6px; }
    .ledger th[scope=row] { color: var(--text); font-weight: 400; font-size: 14px; }
    .ledger td, .ledger th, .by-branch td, .by-branch th { padding: 6px 8px; }
    .ledger tbody tr:hover, .by-branch tbody tr:hover { background: none; }
    .ledger .n, .by-branch .n, .act-lines .n { text-align: end; }
    .ledger tr.back td, .ledger tr.back th { color: var(--muted); }
    /* le net, comme un total de livre de comptes : trait double au-dessus */
    .ledger tr.net th, .ledger tr.net td { font-weight: 700; border-top: 3px double var(--border); }
    .ledger tr.net td { font: 700 19px/1.2 var(--font-code); letter-spacing: .02em; }
    .ledger tr.avg th, .ledger tr.avg td { color: var(--muted); font-size: 13.5px; border-bottom: 0; }
    .by-branch { margin-top: 16px; }
    .act-tabs { display: flex; gap: 6px; margin: 18px 0 0; border-bottom: 1px solid var(--border); }
    .act-tabs button { border: 0; border-bottom: 2px solid transparent; border-radius: 0; background: none; color: var(--muted); padding: 8px 12px; display: inline-flex; gap: 8px; align-items: center; }
    .act-tabs button[aria-selected=true] { color: var(--text); border-bottom-color: var(--accent); font-weight: 600; }
    .act-panel { padding-top: 6px; }
    .act-lines tr.back td { color: var(--muted); }
    .act-lines a.bon-link { color: inherit; text-decoration: underline dotted var(--muted); text-underline-offset: 3px; }
    .act-lines a.bon-link:hover { color: var(--accent); }
    .wait::before { content: ""; display: inline-block; width: 10px; height: 10px; margin-inline-end: 6px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .wait::before { animation: none; } }
    @media (max-width: 760px) {
        .item-head { grid-template-columns: 1fr; }
        .ledgers { grid-template-columns: 1fr; }
        .tag { padding: 18px 18px 16px; padding-inline-start: 48px; }
        .tag-hole { inset-inline-start: 17px; top: 22px; }
        .tag-foot { gap: 22px; }
        .meter-cell { min-width: 140px; }
    }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
    @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('common.keys.search')])
    @if ($designation !== null)
        @can('stock.refresh')
            @include('partials.key', ['keys' => ['Alt', 'R'], 'run' => 'Alt+R', 'label' => __('stock.refresh_now')])
        @endcan
        @if ($activity && $activity['branches'] > 0)
            @include('partials.key', ['keys' => ['Alt', '1…'.count(\App\Support\ProductActivity::PERIODS)], 'label' => __('stock.activity.period')])
        @endif
    @endif
@endpush

@section('content')
<nav class="crumbs" aria-label="{{ __('stock.item.crumbs') }}">
    {{-- Echap ou clic : retour a la liste d'ou l'on vient, avec ses mots et son filtre (data-back, partials/keys) --}}
    <a href="{{ route('stock.index', ['q' => $code]) }}" data-key="Escape" data-back>{{ __('common.nav.search') }}</a>
    <span aria-hidden="true">/</span>
    <span class="code ltr">{{ $code }}</span>
</nav>

@if ($designation === null)
    {{-- inconnu dans les branches affichees : dire pourquoi et quoi faire --}}
    <div class="card">
        <p>{{ __('stock.item.not_found', ['code' => $code]) }}</p>
        <div class="facts-actions">
            <a class="btn" href="{{ route('stock.index', ['q' => $code]) }}">{{ __('stock.item.back') }}</a>
            @if ($scope)
                <form method="post" action="{{ route('branch-scope') }}">@csrf<button class="primary" type="submit" name="branch_id" value="">{{ __('stock.item.show_all') }}</button></form>
            @endif
        </div>
    </div>
@else
    <div class="item-head">
        <div class="tag">
            <span class="tag-hole" aria-hidden="true"></span>
            <h1 class="tag-code ltr">{{ $code }}</h1>
            @if ($others)
                <p class="tag-merged ltr" title="{{ __('stock.item.merged') }}">+ {{ implode(' + ', $others) }}</p>
            @endif
            <p class="tag-desig"><bdi>{{ $designation }}</bdi></p>
            <div class="tag-foot">
                <div>
                    <span class="tag-num">{{ $fmt($total) }}</span>
                    <span class="tag-unit">
                        {{ $scope ? __('stock.item.in_stock_at', ['name' => $scope->name]) : __('stock.item.in_stock') }}
                        @if ($total_state)<span class="tag-flag">{{ __("stock.state.{$total_state}") }}</span>@endif
                    </span>
                </div>
                @if ($retail !== null)
                    <div>
                        <span class="tag-num">{{ $fmt($retail) }}</span>
                        <span class="tag-unit">{{ __('stock.col_retail') }}</span>
                    </div>
                @endif
            </div>
        </div>

        <aside class="card facts">
            <dl>
                <div>
                    <dt>{{ __('stock.item.branches') }}</dt>
                    <dd>{{ __('stock.item.branches_value', ['with' => $branches_with_stock, 'count' => $branches_count]) }}</dd>
                </div>
                @if ($show_sale)
                    <div><dt>{{ __('stock.col_wholesale') }}</dt><dd class="num">{{ $fmt($main_prices['price_wholesale'] ?? null) }}</dd></div>
                @endif
                @if ($show_buy)
                    <div><dt>{{ __('stock.col_buy') }}</dt><dd class="num">{{ $fmt($main_prices['price_buy'] ?? null) }}</dd></div>
                @endif
                @if ($price_branch && $show_sale)
                    <div><dt>{{ __('stock.item.price_ref') }}</dt><dd>{{ $price_branch }}</dd></div>
                @endif
                @if ($scope)
                    <div><dt>{{ __('stock.item.showing') }}</dt><dd>{{ __('stock.item.showing_one', ['name' => $scope->name]) }}</dd></div>
                @endif
            </dl>
            <div class="facts-actions">
                @can('stock.refresh')
                    <button type="button" class="primary" id="refresh" data-key="Alt+R">{{ __('stock.refresh_now') }}</button>
                @endcan
                @can('merge.manage')
                    <a class="btn" href="{{ route('merge.index', ['q' => $code]) }}">{{ __('common.nav.merge') }}</a>
                @endcan
            </div>
            <p class="muted small" id="refresh-note" aria-live="polite"></p>
        </aside>
    </div>

    <section class="card">
        <div class="shelf-head">
            <h2>{{ __('stock.item.shelf') }}</h2>
            <span class="legend">
                <span><span class="qty zero">0,00</span>{{ __('stock.state.zero') }}</span>
                <span><span class="qty negative">-3,00</span>{{ __('stock.state.negative') }}</span>
                <span><span class="qty low">2,00</span>{{ __('stock.state.low') }}</span>
                <span><span class="qty over">90,00</span>{{ __('stock.state.over') }}</span>
            </span>
        </div>
        <div id="shelf">@include('stock._shelf')</div>
    </section>

    @if ($activity)
        @include('stock._activity')
    @endif
@endif
@endsection

@push('scripts')
@if ($designation !== null && $activity)
<script>
(() => {
    // onglets ventes / achats (fleches gauche / droite entre les onglets, comme le veut role=tab)
    const tabs = [...document.querySelectorAll('.act-tabs [role=tab]')];
    const pick = tab => tabs.forEach(t => {
        const on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => pick(tab));
        tab.addEventListener('keydown', e => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            const next = tabs[(i + 1) % tabs.length];
            pick(next);
            next.focus();
        });
    });
})();
</script>
@endif
@if ($designation !== null)
<script>
(() => {
    // "تحديث الآن" : demande a chaque branche affichee, puis le rayon est relu (?partial=1&batch=) jusqu'a la fin
    const btn = document.getElementById('refresh');
    if (!btn) return;
    const shelf = document.getElementById('shelf');
    const note = document.getElementById('refresh-note');
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const CODE = @json($code);
    const ITEM_URL = @json(route('stock.item'));
    @php($texts = ['refresh_now' => __('stock.refresh_now'), 'refreshing' => __('stock.refreshing'), 'reading' => __('stock.js.reading'), 'last_update' => __('stock.js.last_update'), 'slow' => __('stock.js.slow'), 'request_failed' => __('common.request_failed')])
    const T = @json($texts);
    const POLL_MS = 2000, MAX_WAIT_MS = 35000;
    let timer = null;

    const done = () => { btn.disabled = false; btn.textContent = T.refresh_now; shelf.removeAttribute('aria-busy'); };
    btn.addEventListener('click', async () => {
        if (timer) return;
        btn.disabled = true;
        btn.textContent = T.refreshing;
        note.textContent = T.reading;
        shelf.setAttribute('aria-busy', 'true');
        try {
            // batch choisi ici : si la requete est rejouee apres une coupure, le serveur ne cree pas les demandes deux fois
            const res = await fetchRetry(@json(route('stock.refresh')), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({code: CODE, batch: newId()}),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const {batch} = await res.json();
            const started = Date.now();
            const tick = async () => {
                try {
                    const url = new URL(ITEM_URL, location.href);
                    url.search = new URLSearchParams({code: CODE, batch, partial: '1'});
                    const r = await fetchRetry(url, {headers: {Accept: 'text/html'}});
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    shelf.innerHTML = await r.text();
                    if (shelf.querySelector('[data-done="1"]') || Date.now() - started > MAX_WAIT_MS) {
                        note.textContent = T.last_update.replace(':time', new Date().toLocaleTimeString('fr-FR'));
                        timer = null;
                        done();
                        return;
                    }
                } catch (e) { note.textContent = T.slow; }
                timer = setTimeout(tick, POLL_MS);
            };
            timer = setTimeout(tick, 500);
        } catch (e) {
            note.textContent = T.request_failed.replace(':error', e.message);
            done();
        }
    });
})();
</script>
@endif
@endpush
