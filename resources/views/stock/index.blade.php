@extends('layouts.app')

@section('title', $q !== '' ? "{$q} — مخزون الفروع" : 'مخزون الفروع')

@push('head')
<style>
    .search { display: flex; gap: 8px; margin-bottom: 16px; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }
    .code { font-family: Consolas, "Courier New", monospace; font-weight: 600; }
    .total { font-weight: 600; }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
    .modal-bg { position: fixed; inset: 0; background: rgba(0, 0, 0, .45); display: none; align-items: flex-start; justify-content: center; padding: 6vh 12px; z-index: 10; overflow: auto; }
    .modal-bg.open { display: flex; }
    .modal { background: var(--card); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 860px; padding: 16px 18px; }
    .modal-head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
    .modal-head .code { font-size: 18px; }
    .modal-head .grow { flex: 1; }
    .modal .desig { margin: 0 0 12px; }
    .wait::before { content: ""; display: inline-block; width: 10px; height: 10px; margin-left: 6px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
<form class="search" method="get" action="{{ route('stock.index') }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="كود المنتج أو جزء من التسمية — مثلاً 820AC" autofocus autocomplete="off">
    <button class="primary" type="submit">بحث</button>
</form>

<div class="card">
    @if ($q === '')
        <div class="empty">اكتب كود منتج أو جزءاً من تسميته. النتيجة: سطر واحد لكل منتج، ومجموع كمياته في كل الفروع.</div>
    @elseif ($results->isEmpty())
        <div class="empty">لا يوجد منتج يطابق «{{ $q }}» في أي فرع.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>الكود</th>
                <th>التسمية</th>
                <th>المجموع</th>
                <th class="hide-sm">الفروع</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($results as $r)
                <tr>
                    <td><span class="code ltr">{{ $r->code }}</span></td>
                    <td><bdi>{{ $r->designation }}</bdi></td>
                    <td><span class="num total {{ $r->total < 0 ? 'neg' : '' }}">{{ number_format($r->total, 2, ',', ' ') }}</span></td>
                    <td class="hide-sm"><span class="pill">{{ $r->branches }} / {{ $branchesTotal }}</span></td>
                    <td><button type="button" data-code="{{ $r->code }}" data-designation="{{ $r->designation }}">معاينة</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($results->count() >= 100)
            <p class="muted small">تُعرض أول 100 نتيجة فقط — دقّق البحث.</p>
        @endif
    @endif
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
        <table>
            <thead>
            <tr>
                <th>الفرع</th>
                <th>الكود هناك</th>
                <th class="hide-sm">التسمية هناك</th>
                <th>الكمية</th>
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

    function render(data) {
        const html = [];
        for (const b of data.branches) {
            if (!b.rows.length) {
                html.push('<tr><td>' + esc(b.branch) + '</td><td class="muted">—</td><td class="muted hide-sm">غير موجود</td>'
                    + '<td class="muted">—</td><td>' + statusCell(b, null) + '</td></tr>');
                continue;
            }
            for (const r of b.rows) {
                html.push('<tr><td>' + esc(b.branch) + '</td>'
                    + '<td><span class="code ltr">' + esc(r.code) + '</span></td>'
                    + '<td class="hide-sm"><bdi>' + esc(r.designation) + '</bdi></td>'
                    + '<td><span class="num total' + (r.stock < 0 ? ' neg' : '') + '">' + fmt(r.stock) + '</span></td>'
                    + '<td>' + statusCell(b, r) + '</td></tr>');
            }
        }
        mBody.innerHTML = html.join('') || '<tr><td colspan="5" class="muted">لا توجد فروع مفعّلة.</td></tr>';
    }

    async function load(batch) {
        const url = new URL(PRODUCT_URL, location.href);
        url.searchParams.set('code', current);
        if (batch) url.searchParams.set('batch', batch);
        const res = await fetch(url, {headers: {Accept: 'application/json'}});
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
        mNote.textContent = '';
        mBody.innerHTML = '<tr><td colspan="5" class="muted"><span class="wait">تحميل…</span></td></tr>';
        bg.classList.add('open');
        try { render(await load()); }
        catch (e) { mBody.innerHTML = '<tr><td colspan="5" class="neg">تعذّر التحميل (' + esc(e.message) + ')</td></tr>'; }
    }

    async function refresh() {
        if (timer || !current) return;
        mRefresh.disabled = true;
        mRefresh.textContent = 'جارٍ التحديث…';
        mNote.textContent = 'كل فرع يقرأ الكمية من قاعدته الآن — عادةً خلال 15 ثانية.';
        try {
            const res = await fetch(REFRESH_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({code: current}),
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
                } catch (e) { mNote.textContent = 'خطأ مؤقت (' + e.message + ')، إعادة المحاولة…'; }
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
    }

    document.querySelectorAll('button[data-code]').forEach(btn =>
        btn.addEventListener('click', () => open(btn.dataset.code, btn.dataset.designation)));
    mRefresh.addEventListener('click', refresh);
    document.getElementById('m-close').addEventListener('click', close);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && current) close(); });
})();
</script>
@endpush
