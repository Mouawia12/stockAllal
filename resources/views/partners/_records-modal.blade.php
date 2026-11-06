{{--
    La fenetre de la fiche : ses bons, ses versements, ses articles, une page a la fois (PartnerController::records,
    partners/_records). Recherche, filtre, tri, 25 / 50 / 100 lignes. Dedans, une ligne s'ouvre sans quitter la fenetre : un bon
    (documents/_bon, ?partial=1, avec suivant / precedent), ou un article bon par bon (a quel prix il le lui a fait).
    Clavier facon Gescomm : fleches ligne, Entree ou F2 ouvrir, fleches du sens de lecture ou PageUp / PageDown page,
    Echap un niveau en arriere. L'adresse garde l'onglet et la page (#bons/3) : on y revient apres avoir ouvert un bon.
--}}
@php
    $R = __('partners.records');
    $recText = [
        'loading' => $R['loading'],
        'error' => $R['error'],
        'retry' => $R['retry'],
        'prev' => $R['prev'],
        'next' => $R['next'],
        'prev_bon' => $R['prev_bon'],
        'next_bon' => $R['next_bon'],
        'back' => $R['back'],
        'bon_title' => $R['bon_title'],
        'open_bon' => $R['open_bon'],
        'open_item' => $R['open_item'],
        'position' => __('documents.preview.position'),
        'search' => ['bons' => $R['search']['bons'], 'payments' => $money ? $R['search']['payments_money'] : $R['search']['payments'], 'items' => $R['search']['items']],
    ];
    $recPrint = [
        'bons' => route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $period === 'all' ? null : $period, 'show' => 'bons'])),
        'payments' => route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $period === 'all' ? null : $period, 'show' => 'payments'])),
        'items' => route("{$route}.print-card", array_filter(['partner' => $partner->id, 'period' => $period === 'all' ? null : $period, 'show' => 'items'])),
    ];
    $recBase = route("{$route}.records", $partner);
    $recSizes = \App\Http\Controllers\PartnerController::RECORD_SIZES;
@endphp

@push('head')
@include('documents._bon-style')
<style>
    .modal-bg.rec-bg { position: fixed; inset: 0; z-index: 10; display: none; align-items: center; justify-content: center; padding: 22px 16px; background: rgba(10, 18, 24, .52); }
    .modal-bg.rec-bg.open { display: flex; }
    html.rec-open { overflow: hidden; }
    /* hauteur fixe : le pied ne saute pas d'une page a l'autre, seule la liste defile */
    .rec-modal { width: 100%; max-width: 1120px; height: min(100%, 860px); display: flex; flex-direction: column; background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow), 0 24px 60px rgba(0, 0, 0, .28); overflow: hidden; outline: none; }
    .rec-head { display: flex; align-items: center; gap: 8px 12px; padding: 12px 16px 8px; }
    .rec-who { flex: 1; min-width: 0; display: flex; align-items: baseline; gap: 10px; font-weight: 700; font-size: 16px; }
    .rec-who > bdi { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rec-who .code { color: var(--muted); font-size: 15px; flex: none; }
    .rec-x { border: 0; background: none; color: var(--muted); width: 34px; height: 34px; padding: 0; border-radius: 50%; display: grid; place-items: center; font-size: 15px; flex: none; }
    .rec-x:hover { background: var(--row-hover); color: var(--text); }

    .rec-nav { display: flex; align-items: flex-end; justify-content: space-between; gap: 6px 16px; padding: 0 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
    .rec-tabs { display: flex; gap: 2px; min-width: 0; overflow-x: auto; }
    .rec-tabs button { border: 0; border-bottom: 2px solid transparent; border-radius: 0; background: none; color: var(--muted); padding: 7px 12px 9px; display: inline-flex; gap: 8px; align-items: center; white-space: nowrap; }
    .rec-tabs button:hover { color: var(--text); background: none; }
    .rec-tabs button[aria-selected=true] { color: var(--text); border-bottom-color: var(--accent); font-weight: 700; }
    .rec-periods { padding-bottom: 7px; }
    .rec-periods .chip { font-size: 13px; padding: 2px 10px; }

    .rec-tools { padding: 10px 16px 0; }
    .rec-search { position: relative; max-width: 460px; }
    .rec-search svg { position: absolute; inset-inline-start: 10px; top: 50%; width: 16px; height: 16px; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
    .rec-search input { width: 100%; padding-inline-start: 34px; }
    .rec-crumb { display: flex; align-items: center; gap: 6px 14px; padding: 10px 16px; border-bottom: 1px solid var(--border); background: var(--row-hover); flex-wrap: wrap; }
    .rec-back { display: inline-flex; align-items: center; gap: 6px; padding-block: 5px; padding-inline: 8px 12px; background: var(--card); white-space: nowrap; }
    .rec-back svg { width: 16px; height: 16px; }
    .rec-crumb-title { flex: 1; min-width: 120px; display: flex; align-items: baseline; gap: 10px; overflow: hidden; }
    .rec-crumb-title b { font: 700 18px/1.2 var(--font-code); flex: none; }
    .rec-crumb-title bdi { color: var(--muted); font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rec-crumb a.btn { background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; white-space: nowrap; padding: 5px 14px; }
    .rec-crumb a.btn:hover { filter: brightness(1.08); }

    .rec-body { flex: 1; min-height: 0; display: flex; flex-direction: column; transition: opacity .12s; }
    .rec-body.loading, .rec-detail.loading { opacity: .5; }
    .rec-data { flex: 1; min-height: 0; display: flex; flex-direction: column; }
    .rec-sum { display: flex; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 10px 16px; flex-wrap: wrap; }
    .rec-sum .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .rec-sum .chip { font-size: 13px; padding: 3px 11px; gap: 6px; }
    .rec-totals { display: flex; gap: 4px 18px; flex-wrap: wrap; margin: 0; margin-inline-start: auto; }
    .rec-totals div { display: flex; align-items: baseline; gap: 6px; }
    .rec-totals dt { color: var(--muted); font-size: 13px; }
    .rec-totals dd { margin: 0; font: 600 15px/1.3 var(--font-code); }
    .rec-totals .strong dd { font-size: 18px; font-weight: 700; }
    .rec-scroll { flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; scroll-padding-top: 40px; border-top: 1px solid var(--border); }
    .rec-scroll table { min-width: 100%; }
    .rec-scroll th { position: sticky; top: 0; z-index: 1; background: var(--card); box-shadow: inset 0 -1px 0 var(--border); border-bottom: 0; padding: 7px 10px; }
    .rec-scroll th:first-child, .rec-scroll td:first-child { padding-inline-start: 16px; }
    .rec-scroll th:last-child, .rec-scroll td:last-child { padding-inline-end: 16px; }
    .rec-scroll th.n, .rec-scroll td.n { text-align: end; white-space: nowrap; }
    .rec-scroll th.n .sort { flex-direction: row-reverse; }
    .rec-scroll td { padding-block: 9px; white-space: nowrap; }
    .rec-scroll td.cut { white-space: nowrap; max-width: 210px; overflow: hidden; text-overflow: ellipsis; }
    .rec-scroll td.cut.wide { white-space: normal; min-width: 180px; max-width: 340px; overflow-wrap: anywhere; }
    .rec-scroll .strong { font-weight: 700; }
    .rec-scroll a.bon { font-family: var(--font-code); font-weight: 600; font-size: 16px; color: var(--text); }
    .rec-scroll a.code { color: var(--text); }
    .rec-scroll tr[data-bon], .rec-scroll tr[data-code] { cursor: pointer; }
    .rec-scroll tr[data-bon]:hover a.bon, .rec-scroll tr[data-code]:hover a.code { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .rec-scroll tr[tabindex]:focus { outline: 2px solid var(--accent); outline-offset: -2px; background: var(--accent-soft); }
    .rec-scroll tr.back td { color: var(--muted); }
    .rec-scroll .sort { all: unset; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
    .rec-scroll .sort:hover { color: var(--text); }
    .rec-scroll .sort:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 3px; }
    .rec-scroll th[aria-sort] { color: var(--text); }
    .rec-scroll .sort .arrow { color: var(--accent); min-width: 10px; font-family: var(--font-code); }
    .rec-empty { padding: 40px 16px; text-align: center; margin: 0; }
    .rec-error { padding: 40px 16px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 12px; }
    .rec-error p { margin: 0; }

    .rec-detail { flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 16px; transition: opacity .12s; }
    .rec-detail .bon-head { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
    .rec-detail .bon-number { font-size: 46px; }
    .rec-detail h1 { margin: 0; }

    .rec-foot { display: flex; align-items: center; gap: 8px 16px; padding: 9px 16px; border-top: 1px solid var(--border); background: var(--bg); flex-wrap: wrap; }
    .rec-hint { display: flex; gap: 2px 12px; margin: 0; color: var(--muted); font-size: 12.5px; flex-wrap: wrap; }
    .rec-hint span { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .rec-hint kbd { font: 600 12px/1.45 var(--font-code); color: var(--text); background: var(--card); min-width: 18px; text-align: center; }
    .rec-hint[hidden] { display: none; }
    .rec-foot-end { margin-inline-start: auto; display: flex; align-items: center; gap: 8px 14px; flex-wrap: wrap; justify-content: flex-end; }
    .rec-range { font-size: 13.5px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rec-sizes { display: inline-flex; align-items: center; gap: 2px; font-size: 13px; color: var(--muted); }
    .rec-sizes span { margin-inline-start: 4px; white-space: nowrap; }
    .rec-sizes button { border: 0; background: none; padding: 2px 7px; border-radius: 6px; font: 600 13.5px/1.4 var(--font-code); color: var(--muted); }
    .rec-sizes button:hover { background: var(--row-hover); color: var(--text); }
    .rec-sizes button[aria-pressed=true] { background: var(--accent-soft); color: var(--accent); }
    .rec-pager { display: inline-flex; align-items: center; gap: 4px; }
    .rec-pager button { display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-height: 34px; padding: 4px 10px; background: var(--card); white-space: nowrap; }
    .rec-pager button:disabled { opacity: .4; cursor: default; }
    .rec-pager button:disabled:hover { background: var(--card); }
    .rec-pager button.go-end { padding: 4px 8px; }
    .rec-pager button.primary, .rec-pager button.primary:disabled:hover { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
    .rec-pager button.primary:not(:disabled):hover { background: var(--accent); filter: brightness(1.08); }
    .rec-pager svg { width: 15px; height: 15px; flex: none; }
    .rec-page-now { display: inline-flex; align-items: center; gap: 5px; padding-inline: 6px; font-size: 13.5px; color: var(--muted); white-space: nowrap; }
    .rec-page-now input { width: 46px; padding: 4px 4px; text-align: center; font: 600 14px/1.3 var(--font-code); }
    .rec-page-now b { font: 600 14px/1 var(--font-code); color: var(--text); }
    [hidden].rec-page-now, .rec-pager [hidden], .rec-sizes[hidden], .rec-crumb[hidden], .rec-tools[hidden], .rec-body[hidden], .rec-detail[hidden] { display: none; }
    @media (max-width: 1040px) { .rec-hint { display: none; } }
    @media (max-width: 700px) {
        .modal-bg.rec-bg { padding: 0; }
        .rec-modal { max-width: none; height: 100%; border: 0; border-radius: 0; }
        .rec-head { padding: 8px 12px 4px; }
        .rec-nav, .rec-tools, .rec-sum, .rec-crumb, .rec-foot { padding-inline: 12px; }
        .rec-periods { flex-wrap: nowrap; overflow-x: auto; max-width: 100%; }
        .rec-periods .chip { white-space: nowrap; }
        .rec-sizes, .go-end, .rec-print-word { display: none; }
        .rec-foot-end { width: 100%; justify-content: space-between; margin: 0; }
        .rec-pager button { padding: 4px 8px; }
        .rec-scroll th:first-child, .rec-scroll td:first-child { padding-inline-start: 12px; }
        .rec-scroll th:last-child, .rec-scroll td:last-child { padding-inline-end: 12px; }
        .rec-detail { padding: 12px; }
    }
    @media (prefers-reduced-motion: reduce) { .rec-body, .rec-detail { transition: none; } }
</style>
@endpush

<div class="modal-bg rec-bg" id="rec-bg">
    <div class="rec-modal" role="dialog" aria-modal="true" tabindex="-1" aria-label="{{ __('partners.records.dialog', ['name' => $partner->name ?? $partner->code]) }}">
        <div class="rec-head">
            <div class="rec-who"><bdi>{{ $partner->name ?? $partner->code }}</bdi><span class="code ltr">{{ $partner->code }}</span></div>
            <a class="btn print-btn" id="rec-print" href="{{ $recPrint['bons'] }}" target="_blank" data-key="Alt+P" title="{{ __('print.print') }} (Alt+P)">@include('partials.app-icon', ['name' => 'print'])<span class="rec-print-word">{{ __('print.print') }}</span></a>
            <button type="button" class="rec-x" id="rec-close" title="{{ $R['close'] }} (Échap)" aria-label="{{ $R['close'] }}">✕</button>
        </div>
        <div class="rec-nav">
            <div class="rec-tabs" role="tablist" aria-label="{{ $P['activity'][$kind] }}">
                @foreach ($opens as $tab => $open)
                    <button type="button" role="tab" data-tab="{{ $tab }}" data-key="Alt+{{ $open['key'] }}" aria-controls="rec-body" aria-selected="false" tabindex="-1">
                        {{ $P['tabs'][$tab] }} <span class="pill num">{{ number_format($open['n'], 0, ',', ' ') }}</span>
                    </button>
                @endforeach
            </div>
            <nav class="chips rec-periods" aria-label="{{ $P['period'] }}">
                @foreach (\App\Support\ProductActivity::PERIODS as $key => $days)
                    <a href="{{ route("{$route}.show", ['partner' => $partner, 'period' => $key === 'all' ? null : $key]) }}" data-rec-period data-key="Alt+{{ $loop->iteration }}"
                       @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ __("stock.activity.periods.{$key}") }}</a>
                @endforeach
            </nav>
        </div>
        <div class="rec-tools" id="rec-tools">
            <div class="rec-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" id="rec-q" data-key="F3" autocomplete="off" maxlength="60" aria-label="{{ $P['search'] }}">
            </div>
        </div>
        <div class="rec-crumb" id="rec-crumb" hidden>
            <button type="button" class="rec-back" id="rec-back">
                <svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
                <span id="rec-back-label"></span>
            </button>
            <span class="rec-crumb-title"><b id="rec-crumb-title" class="ltr"></b><bdi id="rec-crumb-sub"></bdi></span>
            <a class="btn" id="rec-crumb-open" href="#"></a>
        </div>

        <div class="rec-body" id="rec-body" role="tabpanel" aria-live="polite"></div>
        <div class="rec-detail" id="rec-detail" aria-live="polite" hidden></div>

        <div class="rec-foot">
            <p class="rec-hint" id="rec-hint-list">
                <span><kbd>↑</kbd><kbd>↓</kbd> {{ $R['hint']['rows'] }}</span>
                <span><kbd>Entrée</kbd> {{ $R['hint']['open'] }}</span>
                <span><kbd>←</kbd><kbd>→</kbd> {{ $R['hint']['pages'] }}</span>
                <span><kbd>Échap</kbd> {{ $R['hint']['close'] }}</span>
            </p>
            <p class="rec-hint" id="rec-hint-bon" hidden>
                <span><kbd>↑</kbd><kbd>↓</kbd> {{ $R['hint']['other_bon'] }}</span>
                <span><kbd>Entrée</kbd> {{ $R['open_bon'] }}</span>
                <span><kbd>Échap</kbd> {{ $R['hint']['back'] }}</span>
            </p>
            <div class="rec-foot-end">
                <span class="rec-range" id="rec-range" aria-live="polite"></span>
                <div class="rec-sizes" id="rec-sizes" role="group" aria-label="{{ $R['per_page'] }}">
                    @foreach ($recSizes as $size)
                        <button type="button" data-per="{{ $size }}" aria-pressed="false">{{ $size }}</button>
                    @endforeach
                    <span>{{ $R['per_page'] }}</span>
                </div>
                <div class="rec-pager">
                    <button type="button" class="go-end" id="rec-first" title="{{ $R['first'] }}" aria-label="{{ $R['first'] }}"><svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m11 17-5-5 5-5M18 17l-5-5 5-5"/></svg></button>
                    <button type="button" id="rec-prev"><svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg><span id="rec-prev-label">{{ $R['prev'] }}</span></button>
                    <label class="rec-page-now" id="rec-page-now">{{ $R['page'] }} <input type="text" inputmode="numeric" id="rec-page" autocomplete="off" aria-label="{{ $R['page'] }}"> {{ $R['of'] }} <b id="rec-pages">1</b></label>
                    <button type="button" class="primary" id="rec-next"><span id="rec-next-label">{{ $R['next'] }}</span><svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
                    <button type="button" class="go-end" id="rec-last" title="{{ $R['last'] }}" aria-label="{{ $R['last'] }}"><svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m13 17 5-5-5-5M6 17l5-5-5-5"/></svg></button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(() => {
    const T = @json($recText);
    const BASE = @json($recBase);
    const PERIOD = @json($period);
    const PRINT = @json($recPrint);
    const SIZES = @json($recSizes);
    const $ = id => document.getElementById('rec-' + id);
    const bg = $('bg');
    const modal = bg.querySelector('.rec-modal');
    const body = $('body'), detail = $('detail'), search = $('q'), pageInput = $('page');
    const tabs = [...bg.querySelectorAll('[data-tab]')];
    const pal = document.getElementById('pal');
    const SIZE_KEY = 'partner-records-per';
    const CACHE_MAX = 80;

    const readSize = () => { try { const n = +localStorage.getItem(SIZE_KEY); return SIZES.includes(n) ? n : SIZES[0]; } catch (_) { return SIZES[0]; } };
    const fresh = tab => ({tab, page: 1, q: '', sort: '', dir: '', f: '', code: ''});
    const memo = {};           // ou l'on en etait dans chaque onglet
    const cache = new Map();
    let st = fresh('bons'), parent = null, per = readSize(), pages = 1, total = 0;
    let view = 'list', shown = null, token = 0, pushed = false, opener = null, debounce = null, firstLoad = true;
    let openDetail = null;     // l'en-tete de la vue "article bon par bon"

    const urlOf = s => {
        const p = new URLSearchParams({tab: s.code ? 'lines' : s.tab, per, page: s.page});
        if (PERIOD !== 'all') p.set('period', PERIOD);
        for (const k of ['q', 'sort', 'dir', 'f', 'code']) if (s[k]) p.set(k, s[k]);
        return BASE + '?' + p;
    };
    function fetchHtml(url) {
        if (!cache.has(url)) {
            if (cache.size >= CACHE_MAX) cache.delete(cache.keys().next().value);
            const page = fetchRetry(url, {headers: {Accept: 'text/html'}})
                .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); });
            cache.set(url, page);
            page.catch(() => cache.delete(url));
        }
        return cache.get(url);
    }
    const hashOf = () => '#' + st.tab + (st.page > 1 && !st.code ? '/' + st.page : '');
    const rows = () => [...body.querySelectorAll('tr[data-row]')];
    const bonRows = () => rows().filter(r => r.dataset.bon);

    function focusRow(row) {
        if (!row) return;
        rows().forEach(r => { r.tabIndex = r === row ? 0 : -1; });
        row.focus({preventScroll: true});
        row.scrollIntoView({block: 'nearest'});
    }

    // tout ce qui entoure la liste : onglets, recherche, fil de retour, lien d'impression, pied
    function chrome() {
        const inBon = view === 'bon';
        tabs.forEach(t => {
            const on = t.dataset.tab === st.tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        body.hidden = inBon;
        detail.hidden = !inBon;
        $('tools').hidden = inBon || !!st.code;
        $('crumb').hidden = !inBon && !st.code;
        $('hint-list').hidden = inBon;
        $('hint-bon').hidden = !inBon;
        search.placeholder = T.search[st.tab] || '';
        if (search.value !== st.q) search.value = st.q;
        $('print').href = inBon ? shown.dataset.bon + '/print?auto=1' : PRINT[st.tab];
        if (inBon) {
            $('back-label').textContent = T.back[st.code ? 'lines' : st.tab];
            $('crumb-title').textContent = T.bon_title.replace(':number', shown.dataset.number);
            $('crumb-sub').textContent = '';
            $('crumb-open').textContent = T.open_bon;
            $('crumb-open').href = shown.dataset.bon;
        } else if (st.code && openDetail) {
            $('back-label').textContent = T.back.items;
            $('crumb-title').textContent = openDetail.title;
            $('crumb-sub').textContent = openDetail.sub || '';
            $('crumb-open').textContent = T.open_item;
            $('crumb-open').href = openDetail.url;
        }
        foot();
        if (bg.classList.contains('open')) history.replaceState(history.state, '', hashOf());
    }

    function foot() {
        const inBon = view === 'bon';
        const stepping = inBon && st.tab !== 'payments';
        const data = body.querySelector('.rec-data');
        $('first').hidden = $('last').hidden = inBon;
        $('page-now').hidden = inBon;
        $('sizes').hidden = inBon;
        $('prev').hidden = $('next').hidden = inBon && !stepping;
        $('prev-label').textContent = inBon ? T.prev_bon : T.prev;
        $('next-label').textContent = inBon ? T.next_bon : T.next;
        bg.querySelectorAll('[data-per]').forEach(b => b.setAttribute('aria-pressed', +b.dataset.per === per ? 'true' : 'false'));
        if (stepping) {
            const n = (st.page - 1) * per + bonRows().indexOf(shown) + 1;
            $('range').textContent = T.position.replace(':n', n).replace(':total', total);
            $('prev').disabled = n <= 1;
            $('next').disabled = n >= total;
            return;
        }
        $('range').textContent = inBon ? '' : (data?.dataset.range || '');
        pageInput.value = st.page;
        $('pages').textContent = pages;
        $('first').disabled = $('prev').disabled = st.page <= 1;
        $('last').disabled = $('next').disabled = st.page >= pages;
    }

    function showError(target, retry) {
        target.replaceChildren();
        const box = document.createElement('div');
        box.className = 'rec-error';
        const p = document.createElement('p');
        p.textContent = T.error;
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = T.retry;
        b.addEventListener('click', retry);
        box.append(p, b);
        target.append(box);
    }

    // une page de la liste ; focus : 'first' | 'last' = la ligne qui prend le clavier, ou un selecteur a retrouver
    async function render(focus = null) {
        const mine = ++token;
        view = 'list';
        shown = null;
        chrome();
        if (firstLoad || !body.querySelector('.rec-data')) {
            const wait = document.createElement('p');
            wait.className = 'muted rec-empty';
            wait.textContent = T.loading;
            body.replaceChildren(wait);
        }
        body.classList.add('loading');
        try {
            const html = await fetchHtml(urlOf(st));
            if (mine !== token) return false;
            body.innerHTML = html;
            firstLoad = false;
            const data = body.querySelector('.rec-data');
            pages = +data.dataset.pages;
            total = +data.dataset.total;
            // une page qui n'existe plus (autre recherche, autre taille) : la derniere
            if (+data.dataset.page > pages) { st.page = pages; return render(focus); }
            openDetail = st.code ? {title: data.dataset.title, sub: data.dataset.sub, url: data.dataset.itemUrl} : null;
            chrome();
            const list = rows();
            if (list[0]) list[0].tabIndex = 0;
            if (focus === 'first') focusRow(list[0]);
            else if (focus === 'last') focusRow(list[list.length - 1]);
            else if (focus) body.querySelector(focus)?.focus();
            if (!modal.contains(document.activeElement)) modal.focus();
            // la page suivante, pour que "التالي" soit immediat
            if (st.page < pages) fetchHtml(urlOf({...st, page: st.page + 1})).catch(() => {});
            return true;
        } catch (e) {
            if (mine === token && e.name !== 'AbortError') showError(body, () => render(focus));
            return false;
        } finally {
            if (mine === token) body.classList.remove('loading');
        }
    }

    function goPage(n, focus = null) {
        const page = Math.min(Math.max(1, n || 1), pages);
        if (page === st.page) { foot(); return; }
        st.page = page;
        render(focus ?? (document.activeElement?.closest?.('tr[data-row]') ? 'first' : null));
    }

    async function openBon(row) {
        shown = row;
        view = 'bon';
        chrome();
        const mine = ++token;
        if (!detail.firstChild) detail.textContent = T.loading;
        detail.classList.add('loading');
        $('crumb-open').focus();
        try {
            const html = await fetchHtml(row.dataset.bon + '?partial=1');
            if (mine !== token) return;
            detail.innerHTML = html;
            detail.scrollTop = 0;
        } catch (e) {
            if (mine === token && e.name !== 'AbortError') showError(detail, () => openBon(row));
        } finally {
            if (mine === token) detail.classList.remove('loading');
        }
        // les voisins, pour que suivant / precedent soient immediats
        const list = bonRows(), i = list.indexOf(row);
        if (st.tab !== 'payments') [list[i - 1], list[i + 1]].forEach(n => { if (n) fetchHtml(n.dataset.bon + '?partial=1').catch(() => {}); });
    }

    // bon suivant / precedent, en passant a la page voisine au bout de celle-ci
    async function stepBon(by) {
        if (st.tab === 'payments') return;
        const list = bonRows();
        const next = list[list.indexOf(shown) + by];
        if (next) return openBon(next);
        const page = st.page + by;
        if (page < 1 || page > pages) return;
        st.page = page;
        if (await render()) {
            const fresh = bonRows();
            openBon(by > 0 ? fresh[0] : fresh[fresh.length - 1]);
        }
    }

    function activate(row) {
        if (row.dataset.bon) { openBon(row); return true; }
        if (row.dataset.code) {
            parent = {...st};
            st = {...fresh(st.tab), code: row.dataset.code};
            render('first');
            return true;
        }
        return false;
    }

    // un niveau en arriere : du bon a la liste, de l'article aux articles, puis la fenetre se ferme
    function back() {
        if (view === 'bon') {
            const row = shown;
            token++;
            view = 'list';
            shown = null;
            detail.classList.remove('loading');
            chrome();
            if (row?.isConnected) focusRow(row);
            return;
        }
        if (st.code) {
            const code = st.code;
            st = parent || fresh('items');
            parent = null;
            render(`tr[data-code="${CSS.escape(code)}"]`);
            return;
        }
        close();
    }

    function remember() {
        memo[st.tab] = st.code && parent ? parent : st;
    }
    function showTab(tab, page = 0) {
        remember();
        parent = null;
        st = memo[tab] || fresh(tab);
        if (page) st.page = page;
        render('first');
    }

    function open(tab, page = 0, fromAddress = false) {
        const already = bg.classList.contains('open');
        if (!already) {
            opener = fromAddress ? null : document.activeElement;
            bg.classList.add('open');
            document.documentElement.classList.add('rec-open');
            if (!fromAddress) {
                history.pushState({rec: 1}, '', '#' + tab);
                pushed = true;
            }
        }
        showTab(tab, page);
    }

    function close(fromHistory = false) {
        if (!bg.classList.contains('open')) return;
        remember();
        parent = null;
        view = 'list';
        token++;
        bg.classList.remove('open');
        document.documentElement.classList.remove('rec-open');
        if (!fromHistory) {
            if (pushed) { pushed = false; history.back(); }
            else history.replaceState(null, '', location.pathname + location.search);
        }
        opener?.focus?.();
    }

    // les boutons de la fiche ; survoles, leur premiere page se charge deja
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-open]');
        if (btn) open(btn.dataset.open);
    });
    document.querySelectorAll('[data-open]').forEach(b => {
        const early = () => fetchHtml(urlOf(memo[b.dataset.open] || fresh(b.dataset.open))).catch(() => {});
        b.addEventListener('pointerenter', early);
        b.addEventListener('focus', early);
    });

    tabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));
    $('close').addEventListener('click', () => close());
    $('back').addEventListener('click', back);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });

    // changer de periode recharge la fiche (ses chiffres aussi) et rouvre le meme onglet
    bg.querySelectorAll('[data-rec-period]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        if (a.classList.contains('on')) return;
        location.href = a.href + '#' + st.tab;
    }));

    $('first').addEventListener('click', () => goPage(1));
    $('last').addEventListener('click', () => goPage(pages));
    $('prev').addEventListener('click', () => view === 'bon' ? stepBon(-1) : goPage(st.page - 1));
    $('next').addEventListener('click', () => view === 'bon' ? stepBon(1) : goPage(st.page + 1));
    $('next').addEventListener('pointerenter', () => { if (view === 'list' && st.page < pages) fetchHtml(urlOf({...st, page: st.page + 1})).catch(() => {}); });
    pageInput.addEventListener('focus', () => pageInput.select());
    pageInput.addEventListener('change', () => goPage(parseInt(pageInput.value, 10)));
    pageInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); goPage(parseInt(pageInput.value, 10)); } });

    bg.querySelectorAll('[data-per]').forEach(b => b.addEventListener('click', () => {
        per = +b.dataset.per;
        try { localStorage.setItem(SIZE_KEY, per); } catch (_) { /* navigation privee : pour cette page seulement */ }
        st.page = 1;
        render();
    }));

    search.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            st.q = search.value.trim();
            st.page = 1;
            render();
        }, 250);
    });

    // dans la liste : tri, filtre, ligne. Un lien avec Ctrl ou Maj reste un vrai lien (nouvel onglet).
    body.addEventListener('click', e => {
        const sort = e.target.closest('[data-sort]');
        if (sort) {
            const now = sort.closest('th').getAttribute('aria-sort');
            st.sort = sort.dataset.sort;
            st.dir = now === 'descending' ? 'asc' : 'desc';
            st.page = 1;
            render(`[data-sort="${CSS.escape(st.sort)}"]`);
            return;
        }
        const chip = e.target.closest('[data-filter]');
        if (chip) {
            st.f = chip.dataset.filter;
            st.page = 1;
            render(`[data-filter="${CSS.escape(st.f)}"]`);
            return;
        }
        const row = e.target.closest('tr[data-row]');
        if (!row) return;
        const link = e.target.closest('a');
        if (link && (e.ctrlKey || e.metaKey || e.shiftKey || e.button)) return;
        if (!link && String(getSelection())) return;   // on selectionnait un texte
        if (activate(row)) e.preventDefault();
    });

    document.addEventListener('keydown', e => {
        if (!bg.classList.contains('open') || !pal.hidden || e.altKey || e.ctrlKey || e.metaKey) return;
        // le clavier reste dans la fenetre
        if (e.key === 'Tab') {
            const items = [...modal.querySelectorAll('a[href]:not([tabindex="-1"]), button:not(:disabled):not([tabindex="-1"]), input, tr[tabindex="0"]')].filter(el => el.getClientRects().length);
            if (!items.length) return;
            const active = document.activeElement;
            if (e.shiftKey && (active === items[0] || active === modal)) { e.preventDefault(); items[items.length - 1].focus(); }
            else if (!e.shiftKey && active === items[items.length - 1]) { e.preventDefault(); items[0].focus(); }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            if (e.target === search && search.value) {
                search.value = '';
                st.q = '';
                st.page = 1;
                render();
                return;
            }
            back();
            return;
        }
        const forward = (e.key === 'ArrowLeft') === (document.dir === 'rtl');
        if (view === 'bon') {
            if (e.key === 'F2') { e.preventDefault(); back(); }
            else if (['ArrowDown', 'PageDown'].includes(e.key)) { e.preventDefault(); stepBon(1); }
            else if (['ArrowUp', 'PageUp'].includes(e.key)) { e.preventDefault(); stepBon(-1); }
            else if (['ArrowLeft', 'ArrowRight'].includes(e.key)) { e.preventDefault(); stepBon(forward ? 1 : -1); }
            return;
        }
        if (e.key === 'PageDown' || e.key === 'PageUp') {
            e.preventDefault();
            goPage(st.page + (e.key === 'PageDown' ? 1 : -1));
            return;
        }
        if (e.target === search) {
            if (e.key === 'ArrowDown') { e.preventDefault(); focusRow(rows()[0]); }
            return;
        }
        if (e.target === pageInput) return;
        const tabButton = e.target.closest?.('[data-tab]');
        if (tabButton && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
            e.preventDefault();
            const i = tabs.indexOf(tabButton);
            const t = tabs[(i + (forward ? 1 : tabs.length - 1)) % tabs.length];
            showTab(t.dataset.tab);
            t.focus();
            return;
        }
        const list = rows();
        const row = e.target.closest?.('tr[data-row]');
        const i = list.indexOf(row);
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (i >= list.length - 1 && row) { if (st.page < pages) goPage(st.page + 1, 'first'); }
                else focusRow(list[i + 1]);
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (i <= 0 && row) { if (st.page > 1) goPage(st.page - 1, 'last'); else search.focus(); }
                else if (row) focusRow(list[i - 1]);
                else focusRow(list[0]);
                break;
            case 'ArrowLeft':
            case 'ArrowRight':
                e.preventDefault();
                goPage(st.page + (forward ? 1 : -1), row ? 'first' : null);
                break;
            case 'Home':
                if (row) { e.preventDefault(); focusRow(list[0]); }
                break;
            case 'End':
                if (row) { e.preventDefault(); focusRow(list[list.length - 1]); }
                break;
            case 'F2':
                e.preventDefault();
                if (row || list[0]) activate(row || list[0]);
                break;
            case 'Enter':
                if (row) { e.preventDefault(); activate(row); }
                break;
        }
    });

    // la fleche "retour" du telephone remonte d'un niveau, comme Echap
    addEventListener('popstate', () => {
        if (!bg.classList.contains('open')) return;
        if (view === 'bon' || st.code) {
            back();
            history.pushState({rec: 1}, '', hashOf());
            return;
        }
        pushed = false;
        close(true);
    });

    // l'adresse garde l'onglet et la page : rechargement, retour depuis un bon, lien envoye
    const asked = /^#(bons|payments|items)(?:\/(\d+))?$/.exec(location.hash);
    if (asked) open(asked[1], +asked[2] || 0, true);
})();
</script>
@endpush
