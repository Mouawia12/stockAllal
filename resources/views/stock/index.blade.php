@extends('layouts.app')

{{-- Meme page pour la recherche et pour "دمج الأصناف" ($merge, administrateur) : cases a cocher et retrait d'un code en plus. --}}
@php($baseTitle = ($merge ? __('common.nav.merge').' — ' : '').__('common.app_name'))
@section('title', ($q !== '' ? "{$q} — " : '').$baseTitle)

@push('head')
<style>
    .search { display: flex; gap: 8px; margin-bottom: 16px; }
    .search .q-wrap { position: relative; flex: 1; display: flex; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }
    /* a la fin du champ, avant la croix d'effacement du navigateur */
    .live-spin { display: none; position: absolute; inset-inline-end: 38px; top: 50%; width: 16px; height: 16px; margin-top: -8px;
        border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; }
    .search.busy .live-spin { display: block; }
    .search.offline input { border-color: var(--warn); }
    .live-hint { margin: -8px 0 12px; }
    /* pas en jaune : le jaune veut dire "stock negatif" (couleurs de Gescomm) */
    mark { background: var(--accent-soft); color: inherit; border-radius: 3px; padding: 0 1px; box-shadow: inset 0 -2px 0 var(--accent); }
    #results { transition: opacity .15s; }
    #results[aria-busy="true"] { opacity: .6; }
    #results tbody tr:focus-within { background: var(--row-hover); }
    a.code { color: var(--text); }
    a.code:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .total { font-weight: 600; }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
    .codes { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
    .codes .pill { font-family: var(--font-code); font-size: 13px; }
    .codes button.link { padding: 0 3px; font-size: 12px; }
    .codes button.link:hover { color: var(--bad); background: none; }
    .page-title { font-size: 20px; margin: 0 0 4px; }
    .legend { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; margin: 12px 0 0; }
    /* filtres facon Odoo : une pastille par filtre, son nombre a cote ; les couleurs de Gescomm pour epuise / negatif */
    .filters { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; }
    .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 0; padding-inline: 12px 6px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 14px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip .n { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); background: var(--row-hover); border-radius: 99px; padding: 0 8px; }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .chip.on .n { background: var(--card); color: var(--accent); }
    .chip-zero .n { color: var(--zero); background: var(--zero-bg); }
    .chip-negative .n { color: var(--negative); background: var(--negative-bg); }
    .merge-help { margin: 0 0 14px; }
    .merge-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0 0 4px; }
    .merge-head .page-title { flex: 1; margin: 0; }
    .merge-head .btn { display: inline-flex; align-items: center; gap: 6px; }
    .merge-head .btn svg { width: 18px; height: 18px; }
    .merge-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .merge-bar button:disabled { opacity: .45; cursor: not-allowed; }
    td.pick { width: 1%; }
    td.pick input { width: 18px; height: 18px; }
    .modal-bg { position: fixed; inset: 0; background: rgba(0, 0, 0, .45); display: none; align-items: flex-start; justify-content: center; padding: 6vh 12px; z-index: 10; overflow: auto; }
    .modal-bg.open { display: flex; }
    .modal { background: var(--card); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 860px; padding: 16px 18px; }
    .modal-head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
    .modal-head .code { font-size: 18px; }
    .modal-head .grow { flex: 1; }
    .modal .desig { margin: 0 0 12px; }
    .modal #m-merged { margin: -8px 0 12px; }
    .modal .m-hint { margin: 10px 0 0; text-align: center; }
    @media (max-width: 700px), (hover: none) { .modal .m-hint { display: none; } }
    .wait::before { content: ""; display: inline-block; width: 10px; height: 10px; margin-inline-end: 6px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('common.keys.search')])
    @include('partials.key', ['keys' => ['↑', '↓'], 'label' => __('common.keys.move')])
    @include('partials.key', ['keys' => [__('common.keys.enter')], 'label' => __('common.keys.open')])
    @include('partials.key', ['keys' => [__('common.keys.space')], 'label' => $merge ? __('common.keys.pick') : __('stock.preview')])
    @include('partials.key', ['keys' => ['F2'], 'label' => __('stock.preview')])
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => __('stock.filters.label')])
@endpush

@section('content')
@if ($merge)
    <div class="merge-head">
        <h1 class="page-title">{{ __('common.nav.merge') }}</h1>
        <a class="btn" href="{{ route('merge.ai') }}" data-key="Alt+I">@include('partials.app-icon', ['name' => 'spark']){{ __('merge_ai.button') }}</a>
    </div>
    <p class="muted small merge-help">{!! __('stock.merge.help') !!}</p>
@endif

<form class="search" method="get" action="{{ route($merge ? 'merge.index' : 'stock.index') }}" data-title="{{ $baseTitle }}">
    <div class="q-wrap">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('stock.placeholder') }}" autofocus autocomplete="off" aria-controls="results" data-key="F3">
        <span class="live-spin" aria-hidden="true"></span>
    </div>
    <button class="primary" type="submit">{{ __('stock.search') }}</button>
    {{-- sans JavaScript, le filtre choisi reste a la recherche suivante --}}
    @if ($filter !== 'all')<input type="hidden" name="f" value="{{ $filter }}">@endif
</form>
<p class="live-hint muted small hide-sm">{!! __('stock.live_hint') !!}</p>

@if ($merge)
    <form id="merge-form" method="post" action="{{ route('merge.store') }}">@csrf</form>
    <form id="unmerge-form" method="post" action="{{ route('merge.destroy') }}">@csrf @method('delete')</form>
@endif

<div class="card" id="results">
    @include('stock._results')
</div>

<div class="modal-bg" id="modal-bg">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-code">
        <div class="modal-head">
            <span class="code ltr" id="m-code"></span>
            <span class="grow"></span>
            <a class="btn" id="m-page" href="{{ route('stock.item') }}">{{ __('stock.item.open_page') }}</a>
            @can('stock.refresh')
                <button type="button" class="primary" id="m-refresh" data-key="Alt+R">{{ __('stock.refresh_now') }}</button>
            @endcan
            <button type="button" class="link" id="m-close" aria-label="{{ __('stock.close') }}">✕</button>
        </div>
        <p class="desig muted"><bdi id="m-desig"></bdi></p>
        <p class="muted small" id="m-merged" hidden></p>
        <table>
            <thead>
            <tr>
                <th>{{ __('stock.col_branch') }}</th>
                <th>{{ __('stock.col_code_there') }}</th>
                <th class="hide-sm">{{ __('stock.col_designation_there') }}</th>
                <th>{{ __('stock.col_quantity') }}</th>
                @if ($showSale)
                    <th title="Pu Detail">{{ __('stock.col_retail') }}</th>
                    <th title="Prix Vente Gros">{{ __('stock.col_wholesale') }}</th>
                @endif
                @if ($showBuy)
                    <th class="hide-sm" title="Pu Achat — {{ __('stock.admin_only') }}">{{ __('stock.col_buy') }}</th>
                @endif
                <th>{{ __('stock.col_status') }}</th>
            </tr>
            </thead>
            <tbody id="m-body"></tbody>
        </table>
        <p class="muted small" id="m-note"></p>
        <p class="muted small m-hint">{{ __('stock.preview_hint') }}</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const PRODUCT_URL = @json(route('stock.product'));
    const REFRESH_URL = @json(route('stock.refresh'));
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const POLL_MS = 2000, MAX_WAIT_MS = 35000;
    // textes de la langue choisie (lang/*/stock.php) ; t() remplace les :marqueurs.
    {{-- tableau prepare dans @php : @json() coupe son argument aux virgules --}}
    @php($texts = __('stock.js') + ['refresh_now' => __('stock.refresh_now'), 'refreshing' => __('stock.refreshing'), 'request_failed' => __('common.request_failed'), 'weak_connection' => __('stock.weak_connection'), 'pick_hint' => __('stock.merge.pick_hint')])
    const T = @json($texts);
    const t = (text, values) => text.replace(/:(\w+)/g, (m, key) => key in values ? values[key] : m);

    const bg = document.getElementById('modal-bg');
    const mCode = document.getElementById('m-code');
    const mDesig = document.getElementById('m-desig');
    const mMerged = document.getElementById('m-merged');
    const mBody = document.getElementById('m-body');
    const mNote = document.getElementById('m-note');
    const mRefresh = document.getElementById('m-refresh');
    const mPage = document.getElementById('m-page');
    const PAGE_URL = @json(route('stock.item'));
    let current = null, timer = null, startedAt = 0;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = n => n === null || n === undefined ? '—'
        : Number(n).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function statusCell(b, row) {
        switch (b.status) {
            case 'pending':
            case 'taken': return '<span class="wait muted">' + esc(T.waiting) + '</span>';
            case 'done': return '<span class="pill ok">' + esc(T.now) + '</span>';
            case 'not_found': return '<span class="pill">' + esc(T.not_in_branch) + '</span>';
            case 'error': return '<span class="pill bad">' + esc(T.read_error) + '</span>';
            case 'timeout': return '<span class="pill warn">' + esc(T.no_answer) + (row ? ' — ' + esc(t(T.last_value, {time: row.updated})) : '') + '</span>';
        }
        if (!b.online) return '<span class="pill warn">' + esc(T.offline) + (row ? ' — ' + esc(row.updated) : '') + '</span>';
        return row ? '<span class="muted small">' + esc(row.updated) + '</span>' : '';
    }

    // un prix d'une branche ("price", "price_wholesale", "price_buy"), en evidence s'il differe de la branche de reference
    function priceCell(data, b, r, field, cls) {
        const ref = data.main_prices ? data.main_prices[field] : null;
        const value = r[field] ?? null;
        const differs = !b.ref && ref !== null && ref !== undefined && value !== null && Math.abs(value - ref) >= 0.005;
        return '<td class="' + (cls || '') + '">' + (differs
            ? '<span class="pill warn num" title="' + esc(t(T.main_price, {price: fmt(ref)})) + '">' + fmt(value) + '</span>'
            : '<span class="num">' + fmt(value) + '</span>') + '</td>';
    }

    // seulement les prix que ce compte peut voir (show_sale / show_buy viennent du serveur)
    function priceCells(data, b, r) {
        return (data.show_sale ? priceCell(data, b, r, 'price') + priceCell(data, b, r, 'price_wholesale') : '')
            + (data.show_buy ? priceCell(data, b, r, 'price_buy', 'hide-sm') : '');
    }

    function render(data) {
        const others = (data.codes || []).filter(c => c !== data.code);
        mMerged.hidden = !others.length;
        // chaque code isole (LRI ... PDI) pour garder son ordre dans la phrase arabe
        mMerged.textContent = others.length ? t(T.merged_with, {codes: others.map(c => '\u2066' + c + '\u2069').join(T.list_separator)}) : '';
        const html = [];
        for (const b of data.branches) {
            const name = esc(b.branch) + (b.main ? ' <span class="pill ok">' + esc(T.main) + '</span>' : '');
            // chaque ligne avec la couleur legere de sa branche (Branch::hue)
            const tr = '<tr class="bt" style="--bh: ' + Number(b.hue) + '">';
            if (!b.rows.length) {
                const dashes = (data.show_sale ? '<td class="muted">—</td><td class="muted">—</td>' : '') + (data.show_buy ? '<td class="muted hide-sm">—</td>' : '');
                html.push(tr + '<td>' + name + '</td><td class="muted">—</td><td class="muted hide-sm">' + esc(T.not_found) + '</td>'
                    + '<td class="muted">—</td>' + dashes + '<td>' + statusCell(b, null) + '</td></tr>');
                continue;
            }
            for (const r of b.rows) {
                html.push(tr + '<td>' + name + '</td>'
                    + '<td><span class="code ltr">' + esc(r.code) + '</span></td>'
                    + '<td class="hide-sm"><bdi>' + esc(r.designation) + '</bdi></td>'
                    + '<td><span class="qty total ' + (r.state || '') + '">' + fmt(r.stock) + '</span></td>'
                    + priceCells(data, b, r)
                    + '<td>' + statusCell(b, r) + '</td></tr>');
            }
        }
        mBody.innerHTML = html.join('') || '<tr><td colspan="8" class="muted">' + esc(T.no_branches) + '</td></tr>';
    }

    async function load(batch) {
        const url = new URL(PRODUCT_URL, location.href);
        url.searchParams.set('code', current);
        if (batch) url.searchParams.set('batch', batch);
        const res = await fetchRetry(url, {headers: {Accept: 'application/json'}});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function stopPolling() {
        clearTimeout(timer);
        timer = null;
        if (!mRefresh) return;   // compte sans "تحديث الآن" (permission stock.refresh) : pas de bouton
        mRefresh.disabled = false;
        mRefresh.textContent = T.refresh_now;
    }

    async function open(code, designation) {
        stopPolling();
        current = code;
        mCode.textContent = code;
        mPage.href = PAGE_URL + '?code=' + encodeURIComponent(code);
        mDesig.textContent = designation || '';
        mMerged.hidden = true;
        mNote.textContent = '';
        mBody.innerHTML = '<tr><td colspan="6" class="muted"><span class="wait">' + esc(T.loading) + '</span></td></tr>';
        bg.classList.add('open');
        try { render(await load()); }
        catch (e) { mBody.innerHTML = '<tr><td colspan="6" class="neg">' + esc(t(T.load_failed, {error: e.message})) + '</td></tr>'; }
    }

    async function refresh() {
        if (timer || !current || !mRefresh) return;
        mRefresh.disabled = true;
        mRefresh.textContent = T.refreshing;
        mNote.textContent = T.reading;
        try {
            // batch choisi ici : si la requete est rejouee apres une coupure, le serveur ne cree pas les demandes deux fois
            const res = await fetchRetry(REFRESH_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({code: current, batch: newId()}),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const {batch} = await res.json();
            const code = current;
            startedAt = Date.now();
            const tick = async () => {
                if (current !== code) return;
                try {
                    const data = await load(batch);
                    render(data);
                    if (data.done || Date.now() - startedAt > MAX_WAIT_MS) {
                        mNote.textContent = t(T.last_update, {time: new Date().toLocaleTimeString('fr-FR')});
                        stopPolling();
                        return;
                    }
                } catch (e) { mNote.textContent = T.slow; }
                timer = setTimeout(tick, POLL_MS);
            };
            timer = setTimeout(tick, 500);
        } catch (e) {
            mNote.textContent = t(T.request_failed, {error: e.message});
            stopPolling();
        }
    }

    function close() {
        stopPolling();
        current = null;
        bg.classList.remove('open');
        // retour au bouton "معاينة" d'origine : le clavier reprend la ou il etait
        if (lastPreview && results.contains(lastPreview)) lastPreview.focus();
    }

    // ---------- resultats : evenements delegues, ils survivent au remplacement du bloc par la recherche ----------
    const form = document.querySelector('form.search');
    const input = form.querySelector('input[name=q]');
    const results = document.getElementById('results');
    let lastPreview = null;

    results.addEventListener('click', e => {
        // filtre : meme recherche, autre filtre, sans recharger la page
        const chip = e.target.closest('a[data-filter]');
        if (chip) {
            e.preventDefault();
            filter = chip.dataset.filter;
            form.querySelector('input[name=f]')?.remove();
            liveSearch(input.value.trim(), true);
            return;
        }
        const btn = e.target.closest('button[data-code]');
        if (!btn) return;
        lastPreview = btn;
        open(btn.dataset.code, btn.dataset.designation);
    });
    // clavier facon Gescomm : les fleches passent d'un article a l'autre (sur son code, ou sur "معاينة" si on y est),
    // Entree ouvre la page de l'article (le lien), Espace l'apercu ou, dans "دمج الأصناف", coche l'article ; Echap revient a la recherche.
    // Apercu ouvert : ses touches a lui (plus bas)
    results.addEventListener('keydown', e => {
        const cell = e.target.closest('tbody a.code, button[data-code]');
        if (!cell || current) return;
        const row = cell.closest('tr');
        if (e.key === 'Escape') { e.preventDefault(); input.focus(); return; }
        if (e.key === ' ' && cell.matches('a.code')) {
            e.preventDefault();
            const pick = row.querySelector('td.pick input');
            if (pick) { pick.click(); return; }
            const btn = row.querySelector('button[data-code]');
            lastPreview = cell;
            open(btn.dataset.code, btn.dataset.designation);
            return;
        }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        const list = [...results.querySelectorAll(cell.matches('a.code') ? 'tbody a.code' : 'button[data-code]')];
        const i = list.indexOf(cell) + (e.key === 'ArrowDown' ? 1 : -1);
        if (i < 0) input.focus();
        else list[Math.min(i, list.length - 1)].focus();
    });
    // F2 (comme dans les bons) : l'apercu de l'article designe en dernier, au clavier ou sous la souris (sinon le premier) ;
    // apercu ouvert : ↑ ↓ l'article precedent / suivant de la liste, F2 ou Echap ferme (le clavier revient sur son code)
    let pointed = null;
    const rowButton = el => el?.closest?.('tbody tr')?.querySelector('button[data-code]') || null;
    results.addEventListener('pointerover', e => { pointed = rowButton(e.target) || pointed; });
    results.addEventListener('focusin', e => { pointed = rowButton(e.target) || pointed; });
    function preview(btn) {
        pointed = btn;
        lastPreview = btn.closest('tr').querySelector('a.code') || btn;
        open(btn.dataset.code, btn.dataset.designation);
    }

    mRefresh?.addEventListener('click', refresh);
    document.getElementById('m-close').addEventListener('click', close);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && current) { e.preventDefault(); close(); return; }
        if (e.key === 'F2' && !e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
            if (current) { e.preventDefault(); close(); return; }
            const btn = (pointed?.isConnected && pointed) || results.querySelector('button[data-code]');
            if (btn) { e.preventDefault(); preview(btn); }
            return;
        }
        if (current && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            const list = [...results.querySelectorAll('button[data-code]')];
            const next = list[list.indexOf(rowButton(lastPreview)) + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) preview(next);
            return;
        }
        // "/" : retour a la recherche depuis n'importe ou (sauf en saisie)
        if (e.key === '/' && !current && !e.target.closest('input, textarea, select, [contenteditable]')) {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });

    @if ($merge)
    // page "دمج الأصناف" : la selection survit aux recherches successives (820AC, puis 820-AC) ; il faut deux codes
    const picked = new Set();
    const mergeForm = document.getElementById('merge-form');
    function bindMerge() {
        const btn = document.getElementById('merge-btn');
        if (!btn) return;
        const count = document.getElementById('merge-count');
        const boxes = [...results.querySelectorAll('input[name="codes[]"]')];
        const update = () => {
            btn.disabled = picked.size < 2;
            count.textContent = picked.size
                ? t(T.picked, {codes: [...picked].map(c => '\u2066' + c + '\u2069').join(T.list_separator)})
                : T.pick_hint;
        };
        boxes.forEach(b => {
            b.checked = picked.has(b.value);
            b.addEventListener('change', () => { b.checked ? picked.add(b.value) : picked.delete(b.value); update(); });
        });
        update();
    }
    // les codes choisis dans une recherche precedente ne sont plus a l'ecran : ils partent en champs caches
    mergeForm.addEventListener('submit', () => {
        mergeForm.querySelectorAll('input[type=hidden][name="codes[]"]').forEach(i => i.remove());
        picked.forEach(code => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'codes[]';
            i.value = code;
            mergeForm.appendChild(i);
        });
    });
    bindMerge();
    @endif

    // ---------- recherche pendant la frappe ----------
    // Pause de 220 ms apres la derniere touche ; la requete precedente est annulee, et une reponse arrivee
    // en retard (seq) n'ecrase jamais une plus recente. Sans JavaScript, le formulaire marche comme avant.
    const BASE_TITLE = form.dataset.title;
    let typingTimer = null, inflight = null, seq = 0, shown = input.value.trim(), filter = @json($filter);

    async function liveSearch(q, force = false) {
        if (q === shown && !force) return;
        const my = ++seq;
        if (inflight) inflight.abort();
        inflight = new AbortController();

        const url = new URL(form.action, location.href);
        if (q) url.searchParams.set('q', q);
        if (filter !== 'all') url.searchParams.set('f', filter);
        history.replaceState(null, '', url);   // actualiser ou revenir garde la recherche
        document.title = (q ? q + ' — ' : '') + BASE_TITLE;
        url.searchParams.set('partial', '1');

        form.classList.add('busy');
        results.setAttribute('aria-busy', 'true');
        try {
            const res = await fetchRetry(url, {headers: {Accept: 'text/html'}, signal: inflight.signal}, 2, 8000);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            if (my !== seq) return;
            results.innerHTML = html;
            shown = q;
            form.classList.remove('offline');
            form.title = '';
            @if ($merge) bindMerge(); @endif
        } catch (e) {
            if (my !== seq || e.name === 'AbortError') return;
            // les resultats precedents restent ; la prochaine touche (ou Entree) reessaie
            form.classList.add('offline');
            form.title = T.weak_connection;
        } finally {
            if (my === seq) {
                form.classList.remove('busy');
                results.removeAttribute('aria-busy');
            }
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => liveSearch(input.value.trim()), 220);
    });
    input.addEventListener('keydown', e => {
        const first = results.querySelector('tbody a.code');
        if (e.key === 'ArrowDown' && first) {
            e.preventDefault();
            first.focus();
        } else if (e.key === 'Escape' && input.value) {
            e.preventDefault();
            input.value = '';
            clearTimeout(typingTimer);
            liveSearch('');
        }
    });
    form.addEventListener('submit', e => {
        e.preventDefault();
        clearTimeout(typingTimer);
        liveSearch(input.value.trim(), true);
    });
})();
</script>
@endpush
