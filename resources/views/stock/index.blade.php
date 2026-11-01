@extends('layouts.app')

{{-- Meme page pour la recherche et pour "دمج الأصناف" ($merge, administrateur) : cases a cocher et retrait d'un code en plus. --}}
@section('title', ($q !== '' ? "{$q} — " : '').($merge ? 'دمج الأصناف — ' : '').'مخزون الفروع')

@push('head')
<style>
    .search { display: flex; gap: 8px; margin-bottom: 16px; }
    .search input { flex: 1; font-size: 16px; padding: 10px 12px; }
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

<form class="search" method="get" action="{{ route($merge ? 'merge.index' : 'stock.index') }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="كود المنتج أو جزء من التسمية — مثلاً 820AC" autofocus autocomplete="off">
    <button class="primary" type="submit">بحث</button>
</form>

@if ($merge)
    <form id="merge-form" method="post" action="{{ route('merge.store') }}">@csrf</form>
    <form id="unmerge-form" method="post" action="{{ route('merge.destroy') }}">@csrf @method('delete')</form>
@endif

<div class="card">
    @if ($q === '')
        <div class="empty">
            @if ($merge)
                ابحث بجزء مشترك من الكود (مثلاً <span class="ltr">820</span>) لترى كل كتاباته في الفروع.
            @else
                اكتب كود منتج أو جزءاً من تسميته. النتيجة: سطر واحد لكل منتج، ومجموع كمياته في كل الفروع.
            @endif
        </div>
    @elseif ($results->isEmpty())
        <div class="empty">لا يوجد منتج يطابق «{{ $q }}» في أي فرع.</div>
    @else
        @if ($merge)
            <div class="merge-bar">
                <button class="primary" type="submit" form="merge-form" id="merge-btn" disabled>دمج المحدد</button>
                <span class="muted small" id="merge-count">حدّد صنفين أو أكثر هما نفس المنتج.</span>
            </div>
        @endif
        <table>
            <thead>
            <tr>
                @if ($merge)<th></th>@endif
                <th>الكود</th>
                <th>التسمية</th>
                <th>المجموع</th>
                @if ($mainBranch)
                    <th title="سعر الفرع الرئيسي «{{ $mainBranch->name }}»">السعر</th>
                @endif
                <th class="hide-sm">الفروع</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($results as $r)
                <tr>
                    @if ($merge)
                        <td class="pick"><input type="checkbox" name="codes[]" value="{{ $r->code }}" form="merge-form" aria-label="تحديد {{ $r->code }}"></td>
                    @endif
                    <td>
                        <span class="codes">
                            <span class="code ltr">{{ $r->code }}</span>
                            @if ($merge && $r->merged)
                                <button type="submit" class="link" form="unmerge-form" name="code" value="{{ $r->code }}" title="إخراج {{ $r->code }} من الدمج">✕</button>
                            @endif
                            @foreach ($r->merged as $other)
                                <span class="pill ltr" title="مدمج مع {{ $r->code }}">+ {{ $other }}@if ($merge)<button type="submit" class="link" form="unmerge-form" name="code" value="{{ $other }}" title="إخراج {{ $other }} من الدمج">✕</button>@endif</span>
                            @endforeach
                        </span>
                    </td>
                    <td><bdi>{{ $r->designation }}</bdi></td>
                    <td><span class="num total {{ $r->total < 0 ? 'neg' : '' }}">{{ number_format($r->total, 2, ',', ' ') }}</span></td>
                    @if ($mainBranch)
                        <td>
                            <span class="num">{{ $r->price === null ? '—' : number_format($r->price, 2, ',', ' ') }}</span>
                            @if ($r->price_differs)
                                <span class="pill warn" title="فرع واحد على الأقل يبيع بسعر آخر — التفاصيل في «معاينة»">سعر مختلف</span>
                            @endif
                        </td>
                    @endif
                    <td class="hide-sm"><span class="pill">{{ $r->branches }} / {{ $branchesTotal }}</span></td>
                    <td><button type="button" data-code="{{ $r->code }}" data-designation="{{ $r->designation }}">معاينة</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($results->count() >= 100)
            <p class="muted small">تُعرض أول 100 نتيجة فقط — دقّق البحث.</p>
        @endif
        @if (! $mainBranch && auth()->user()->is_admin)
            <p class="muted small">لعرض الأسعار في البحث: حدّد «الفرع الرئيسي» في صفحة <a href="{{ route('branches.index') }}">الفروع</a>.</p>
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
    }

    document.querySelectorAll('button[data-code]').forEach(btn =>
        btn.addEventListener('click', () => open(btn.dataset.code, btn.dataset.designation)));
    mRefresh.addEventListener('click', refresh);
    document.getElementById('m-close').addEventListener('click', close);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && current) close(); });

    @if ($merge)
    // page "دمج الأصناف" : le bouton s'active a partir de deux cases cochees
    const mergeBtn = document.getElementById('merge-btn');
    if (mergeBtn) {
        const boxes = [...document.querySelectorAll('input[name="codes[]"]')];
        const count = document.getElementById('merge-count');
        const update = () => {
            const n = boxes.filter(b => b.checked).length;
            mergeBtn.disabled = n < 2;
            count.textContent = n ? 'محدد: ' + n : 'حدّد صنفين أو أكثر هما نفس المنتج.';
        };
        boxes.forEach(b => b.addEventListener('change', update));
        update();
    }
    @endif
})();
</script>
@endpush
