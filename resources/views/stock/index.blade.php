@extends('layouts.app')

{{-- Meme page pour la recherche et pour "دمج الأصناف" ($merge, administrateur) : cases a cocher et retrait d'un code en plus. --}}
@section('title', ($q !== '' ? "{$q} — " : '').($merge ? 'دمج الأصناف — ' : '').'مخزون الفروع')

@push('head')
<style>
    .search { display: flex; gap: 8px; margin-bottom: 16px; }
    .search .q-wrap { position: relative; flex: 1; display: flex; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }
    .live-spin { display: none; position: absolute; left: 38px; top: 50%; width: 16px; height: 16px; margin-top: -8px;
        border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; }
    .search.busy .live-spin { display: block; }
    .search.offline input { border-color: var(--warn); }
    .live-hint { margin: -8px 0 12px; }
    kbd { font: 12px Consolas, monospace; border: 1px solid var(--border); border-bottom-width: 2px; border-radius: 5px; padding: 0 5px; background: var(--card); }
    mark { background: var(--warn-bg); color: inherit; border-radius: 3px; padding: 0 1px; }
    #results { transition: opacity .15s; }
    #results[aria-busy="true"] { opacity: .6; }
    #results tbody tr:focus-within { background: var(--row-hover); }
    .code { font-family: Consolas, "Courier New", monospace; font-weight: 600; }
    .total { font-weight: 600; }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
    .codes { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
    .codes .pill { font-family: Consolas, "Courier New", monospace; }
    .codes button.link { padding: 0 3px; font-size: 12px; }
    .codes button.link:hover { color: var(--bad); background: none; }
    .page-title { font-size: 20px; margin: 0 0 4px; }
    .merge-help { margin: 0 0 14px; }
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
    .wait::before { content: ""; display: inline-block; width: 10px; height: 10px; margin-left: 6px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
@if ($merge)
    <h1 class="page-title">دمج الأصناف</h1>
    <p class="muted small merge-help">
        نفس المنتج قد يُكتب بكود مختلف في كل فرع (مثلاً <span class="ltr">820AC</span> و <span class="ltr">820-AC</span>).
        ابحث، حدّد الأصناف التي هي نفس المنتج، ثم «دمج المحدد»: تظهر بعدها سطراً واحداً في البحث، والمعاينة و«تحديث الآن» تشمل كل أكوادها.
        زر ✕ بجانب الكود يُخرجه من الدمج.
    </p>
@endif

<form class="search" method="get" action="{{ route($merge ? 'merge.index' : 'stock.index') }}" data-title="{{ $merge ? 'دمج الأصناف — ' : '' }}مخزون الفروع">
    <div class="q-wrap">
        <input type="search" name="q" value="{{ $q }}" placeholder="كود المنتج أو كلمات من التسمية — مثلاً 820AC أو boulon 20" autofocus autocomplete="off" aria-controls="results">
        <span class="live-spin" aria-hidden="true"></span>
    </div>
    <button class="primary" type="submit">بحث</button>
</form>
<p class="live-hint muted small hide-sm">النتائج تتحدّث أثناء الكتابة. <kbd>↓</kbd> <kbd>↑</kbd> للتنقل، <kbd>Enter</kbd> للمعاينة، <kbd>/</kbd> للرجوع إلى البحث.</p>

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
            <button type="button" class="primary" id="m-refresh">تحديث الآن</button>
            <button type="button" class="link" id="m-close" aria-label="إغلاق">✕</button>
        </div>
        <p class="desig muted"><bdi id="m-desig"></bdi></p>
        <p class="muted small" id="m-merged" hidden></p>
        <table>
            <thead>
            <tr>
                <th>الفرع</th>
                <th>الكود هناك</th>
                <th class="hide-sm">التسمية هناك</th>
                <th>الكمية</th>
                <th>السعر</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody id="m-body"></tbody>
        </table>
        <p class="muted small" id="m-note"></p>
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

    const bg = document.getElementById('modal-bg');
    const mCode = document.getElementById('m-code');
    const mDesig = document.getElementById('m-desig');
    const mMerged = document.getElementById('m-merged');
    const mBody = document.getElementById('m-body');
    const mNote = document.getElementById('m-note');
    const mRefresh = document.getElementById('m-refresh');
    let current = null, timer = null, startedAt = 0;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = n => n === null || n === undefined ? '—'
        : Number(n).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function statusCell(b, row) {
        switch (b.status) {
            case 'pending':
            case 'taken': return '<span class="wait muted">في الانتظار…</span>';
            case 'done': return '<span class="pill ok">الآن ✓</span>';
            case 'not_found': return '<span class="pill">غير موجود في الفرع</span>';
            case 'error': return '<span class="pill bad">خطأ في القراءة</span>';
            case 'timeout': return '<span class="pill warn">لم يرد' + (row ? ' — آخر قيمة ' + esc(row.updated) : '') + '</span>';
        }
        if (!b.online) return '<span class="pill warn">غير متصل' + (row ? ' — ' + esc(row.updated) : '') + '</span>';
        return row ? '<span class="muted small">' + esc(row.updated) + '</span>' : '';
    }

    // prix d'une branche, en evidence s'il differe de celui de la branche principale
    function priceCell(data, b, r) {
        const differs = !b.main && data.main_price !== null && r.price !== null && Math.abs(r.price - data.main_price) >= 0.005;
        return differs
            ? '<span class="pill warn num" title="الفرع الرئيسي: ' + fmt(data.main_price) + '">' + fmt(r.price) + '</span>'
            : '<span class="num">' + fmt(r.price) + '</span>';
    }

    function render(data) {
        const others = (data.codes || []).filter(c => c !== data.code);
        mMerged.hidden = !others.length;
        // chaque code isole (LRI ... PDI) pour garder son ordre dans la phrase arabe
        mMerged.textContent = others.length ? 'مدمج مع: ' + others.map(c => '\u2066' + c + '\u2069').join('، ') : '';
        const html = [];
        for (const b of data.branches) {
            const name = esc(b.branch) + (b.main ? ' <span class="pill ok">رئيسي</span>' : '');
            if (!b.rows.length) {
                html.push('<tr><td>' + name + '</td><td class="muted">—</td><td class="muted hide-sm">غير موجود</td>'
                    + '<td class="muted">—</td><td class="muted">—</td><td>' + statusCell(b, null) + '</td></tr>');
                continue;
            }
            for (const r of b.rows) {
                html.push('<tr><td>' + name + '</td>'
                    + '<td><span class="code ltr">' + esc(r.code) + '</span></td>'
                    + '<td class="hide-sm"><bdi>' + esc(r.designation) + '</bdi></td>'
                    + '<td><span class="num total' + (r.stock < 0 ? ' neg' : '') + '">' + fmt(r.stock) + '</span></td>'
                    + '<td>' + priceCell(data, b, r) + '</td>'
                    + '<td>' + statusCell(b, r) + '</td></tr>');
            }
        }
        mBody.innerHTML = html.join('') || '<tr><td colspan="6" class="muted">لا توجد فروع مفعّلة.</td></tr>';
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
        mRefresh.disabled = false;
        mRefresh.textContent = 'تحديث الآن';
    }

    async function open(code, designation) {
        stopPolling();
        current = code;
        mCode.textContent = code;
        mDesig.textContent = designation || '';
        mMerged.hidden = true;
        mNote.textContent = '';
        mBody.innerHTML = '<tr><td colspan="6" class="muted"><span class="wait">تحميل…</span></td></tr>';
        bg.classList.add('open');
        try { render(await load()); }
        catch (e) { mBody.innerHTML = '<tr><td colspan="6" class="neg">تعذّر التحميل (' + esc(e.message) + ')</td></tr>'; }
    }

    async function refresh() {
        if (timer || !current) return;
        mRefresh.disabled = true;
        mRefresh.textContent = 'جارٍ التحديث…';
        mNote.textContent = 'كل فرع يقرأ الكمية من قاعدته الآن — عادةً خلال 15 ثانية.';
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
                        mNote.textContent = 'آخر تحديث: ' + new Date().toLocaleTimeString('fr-FR');
                        stopPolling();
                        return;
                    }
                } catch (e) { mNote.textContent = 'الاتصال بطيء، إعادة المحاولة…'; }
                timer = setTimeout(tick, POLL_MS);
            };
            timer = setTimeout(tick, 500);
        } catch (e) {
            mNote.textContent = 'تعذّر إرسال الطلب (' + e.message + ')';
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
    const previewButtons = () => [...results.querySelectorAll('button[data-code]')];
    let lastPreview = null;

    results.addEventListener('click', e => {
        const btn = e.target.closest('button[data-code]');
        if (!btn) return;
        lastPreview = btn;
        open(btn.dataset.code, btn.dataset.designation);
    });
    // fleches : de la recherche aux boutons "معاينة" et entre eux ; Entree ouvre l'apercu (clic natif)
    results.addEventListener('keydown', e => {
        const btn = e.target.closest('button[data-code]');
        if (!btn || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
        e.preventDefault();
        const list = previewButtons();
        const i = list.indexOf(btn) + (e.key === 'ArrowDown' ? 1 : -1);
        if (i < 0) input.focus();
        else list[Math.min(i, list.length - 1)].focus();
    });
    mRefresh.addEventListener('click', refresh);
    document.getElementById('m-close').addEventListener('click', close);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && current) { close(); return; }
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
                ? 'محدد: ' + [...picked].map(c => '\u2066' + c + '\u2069').join('، ')
                : 'حدّد صنفين أو أكثر هما نفس المنتج.';
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
    let typingTimer = null, inflight = null, seq = 0, shown = input.value.trim();

    async function liveSearch(q, force = false) {
        if (q === shown && !force) return;
        const my = ++seq;
        if (inflight) inflight.abort();
        inflight = new AbortController();

        const url = new URL(form.action, location.href);
        if (q) url.searchParams.set('q', q);
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
            form.title = 'الاتصال ضعيف: النتائج المعروضة قد لا تكون آخر بحث';
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
        if (e.key === 'ArrowDown' && previewButtons().length) {
            e.preventDefault();
            previewButtons()[0].focus();
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
