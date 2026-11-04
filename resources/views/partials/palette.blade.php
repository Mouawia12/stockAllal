{{--
    Barre Ctrl+K, partout : chercher un article (par code ou designation, route "palette") ou une page, au clavier.
    Ctrl+K ou ? l'ouvre (touches physiques : marche aussi avec un clavier arabe), le bouton de la barre du haut aussi.
    Vide, elle montre les pages et les raccourcis. Les pages : seulement celles que le compte peut ouvrir.
--}}
@php
    $u = auth()->user();
    $palPages = array_values(array_filter([
        ['label' => __('common.nav.search'), 'hint' => __('common.apps.stock'), 'url' => route('stock.index')],
        $u->can('merge.manage') ? ['label' => __('common.nav.merge'), 'hint' => __('common.apps.stock'), 'url' => route('merge.index')] : null,
        $u->can('branches.view') ? ['label' => __('common.nav.branches'), 'hint' => __('common.apps.stock'), 'url' => route('branches.index')] : null,
        $u->managesAccounts() ? ['label' => __('common.nav.users'), 'hint' => __('common.apps.settings'), 'url' => route('users.index')] : null,
        ['label' => __('common.account'), 'hint' => __('common.apps.settings'), 'url' => route('account.edit')],
        ['label' => __('common.apps_launcher'), 'hint' => __('common.app_name'), 'url' => route('home')],
    ]));
    $palKeys = [
        ['keys' => ['Ctrl', 'K'], 'label' => __('common.palette.key_palette')],
        ['keys' => ['/'], 'label' => __('common.palette.key_search')],
        ['keys' => ['↑', '↓'], 'label' => __('common.palette.key_move')],
        ['keys' => [__('common.keys.enter')], 'label' => __('common.palette.key_open')],
        ['keys' => [__('common.keys.esc')], 'label' => __('common.palette.key_close')],
    ];
    $palTexts = __('common.palette') + ['states' => __('stock.state')];
@endphp
<div class="pal-bg" id="pal" hidden>
    <div class="pal" role="dialog" aria-modal="true" aria-label="{{ __('common.palette.title') }}">
        <label class="pal-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="pal-q" dir="auto" placeholder="{{ __('common.palette.placeholder') }}" autocomplete="off" spellcheck="false"
                   role="combobox" aria-expanded="true" aria-controls="pal-list" aria-autocomplete="list">
            <kbd>{{ __('common.keys.esc') }}</kbd>
        </label>
        <div class="pal-list" id="pal-list" role="listbox"></div>
    </div>
</div>
<style>
    .palette-open { display: flex; align-items: center; gap: 8px; color: var(--muted); padding: 5px 8px 5px 12px; border-radius: 99px; white-space: nowrap; flex: none; }
    .palette-open svg { width: 16px; height: 16px; }
    .palette-open:hover { color: var(--text); }
    .pal-bg { position: fixed; inset: 0; z-index: 30; background: rgba(10, 18, 24, .45); display: flex; justify-content: center; align-items: flex-start; padding: 9vh 12px 12px; }
    .pal-bg[hidden] { display: none; }
    .pal { width: 100%; max-width: 620px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 24px 60px rgba(0, 0, 0, .28); overflow: hidden; }
    .pal-input { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: text; }
    .pal-input svg { width: 20px; height: 20px; color: var(--accent); flex: none; }
    .pal-input input { flex: 1; border: 0; outline: 0; padding: 4px 0; font-size: 17px; background: none; }
    .pal-input input:focus { outline: 0; }
    .pal-list { max-height: min(60vh, 460px); overflow: auto; padding: 6px; }
    .pal-group { font-size: 12.5px; color: var(--muted); padding: 10px 10px 4px; font-weight: 600; }
    .pal-item { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 9px; color: var(--text); cursor: pointer; text-decoration: none; }
    .pal-item.on { background: var(--accent-soft); }
    .pal-item .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pal-item .code { min-width: 74px; }
    .pal-item .hint { color: var(--muted); font-size: 13px; }
    .pal-keys { display: flex; gap: 4px; }
    .pal-empty { padding: 18px 12px; color: var(--muted); text-align: center; }
    /* barre du haut chargee : le mot d'abord, puis la touche, l'icone reste */
    @media (max-width: 1000px) { .palette-open .palette-label { display: none; } }
    @media (max-width: 700px) { .palette-open kbd { display: none; } .palette-open { padding: 6px; } }
</style>
<script>
(() => {
    const URL_PALETTE = @json(route('palette'));
    const PAGES = @json($palPages);
    const KEYS = @json($palKeys);
    const T = @json($palTexts);
    const bg = document.getElementById('pal');
    const input = document.getElementById('pal-q');
    const list = document.getElementById('pal-list');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = n => Number(n).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let items = [], active = 0, timer = null, ctrl = null, seq = 0, opener = null;

    function render(products, q) {
        const html = [];
        items = [];
        const add = (url, inner) => { items.push(url); html.push('<a class="pal-item" role="option" href="' + esc(url) + '" data-i="' + (items.length - 1) + '">' + inner + '</a>'); };
        if (q) {
            if (products === null) html.push('<div class="pal-empty">' + esc(T.searching) + '</div>');
            else if (products.length) {
                html.push('<div class="pal-group">' + esc(T.products) + '</div>');
                products.forEach(p => add(p.url, '<span class="code ltr">' + esc(p.code) + '</span>'
                    + '<span class="grow"><bdi>' + esc(p.designation) + '</bdi>' + (p.merged.length ? ' <span class="hint ltr">+ ' + esc(p.merged.join(' + ')) + '</span>' : '') + '</span>'
                    + '<span class="qty ' + (p.state || '') + '" title="' + esc(p.state ? T.states[p.state] : '') + '">' + fmt(p.total) + '</span>'));
            }
        }
        const needle = q.toLowerCase();
        const pages = PAGES.filter(p => !needle || p.label.toLowerCase().includes(needle) || p.hint.toLowerCase().includes(needle));
        if (pages.length) {
            html.push('<div class="pal-group">' + esc(T.pages) + '</div>');
            pages.forEach(p => add(p.url, '<span class="grow">' + esc(p.label) + '</span><span class="hint">' + esc(p.hint) + '</span>'));
        }
        if (!q) {
            html.push('<div class="pal-group">' + esc(T.shortcuts) + '</div>');
            KEYS.forEach(k => html.push('<div class="pal-item" aria-hidden="true"><span class="pal-keys">' + k.keys.map(x => '<kbd>' + esc(x) + '</kbd>').join('') + '</span><span class="grow hint">' + esc(k.label) + '</span></div>'));
        } else if (products !== null && !products.length && !pages.length) {
            html.push('<div class="pal-empty">' + esc(T.nothing.replace(':q', q)) + '</div>');
        }
        list.innerHTML = html.join('');
        active = Math.min(active, Math.max(items.length - 1, 0));
        mark();
    }

    function mark() {
        list.querySelectorAll('a.pal-item').forEach(a => a.classList.toggle('on', Number(a.dataset.i) === active));
        list.querySelector('a.pal-item.on')?.scrollIntoView({block: 'nearest'});
    }

    async function search(q) {
        const my = ++seq;
        if (ctrl) ctrl.abort();
        if (!q) { render([], ''); return; }
        render(null, q);
        ctrl = new AbortController();
        try {
            const url = new URL(URL_PALETTE, location.href);
            url.searchParams.set('q', q);
            const res = await fetchRetry(url, {headers: {Accept: 'application/json'}, signal: ctrl.signal}, 2, 8000);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (my === seq) { active = 0; render(data.products, q); }
        } catch (e) {
            if (my === seq && e.name !== 'AbortError') list.innerHTML = '<div class="pal-empty">' + esc(T.failed) + '</div>';
        }
    }

    function open() {
        opener = document.activeElement;
        bg.hidden = false;
        input.value = '';
        active = 0;
        render([], '');
        input.focus();
    }
    function close() {
        bg.hidden = true;
        if (ctrl) ctrl.abort();
        opener?.focus?.();
    }
    window.openPalette = open;

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => search(input.value.trim()), 160); });
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (items.length) { active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; mark(); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (items[active]) location.href = items[active];
        }
    });
    list.addEventListener('mousemove', e => { const a = e.target.closest('a.pal-item'); if (a && Number(a.dataset.i) !== active) { active = Number(a.dataset.i); mark(); } });
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.querySelectorAll('[data-palette]').forEach(b => b.addEventListener('click', open));
    document.addEventListener('keydown', e => {
        if (!bg.hidden) { if (e.key === 'Escape') { e.preventDefault(); close(); } return; }
        const typing = e.target.closest('input, textarea, select, [contenteditable]');
        // e.code = la touche physique : Ctrl+K marche aussi quand le clavier est en arabe ; ? = Maj + la touche de "/"
        if ((e.ctrlKey || e.metaKey) && e.code === 'KeyK') { e.preventDefault(); open(); }
        else if (!typing && (e.key === '?' || e.key === '؟')) { e.preventDefault(); open(); }
    });
})();
</script>
