@extends('layouts.app')

@section('title', 'الفروع — مخزون الفروع')

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    .code-box { border: 2px dashed var(--accent); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; background: var(--card); }
    .code-box .code { font: 600 26px/1.3 Consolas, "Courier New", monospace; letter-spacing: 2px; direction: ltr; unicode-bidi: isolate; }
    .rename { display: flex; gap: 6px; }
    .rename input { width: 170px; padding: 5px 8px; }
    .rename button { padding: 5px 10px; }
    .actions { display: flex; flex-wrap: wrap; gap: 6px; }
    .actions button { padding: 5px 10px; white-space: nowrap; }
    .sync { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .sync button { padding: 3px 10px; font-size: 13px; white-space: nowrap; }
    .sync-msg { font-size: 12px; }
    tr.off td { opacity: .55; }
    .wait::before { content: ""; display: inline-block; width: 9px; height: 9px; margin-left: 5px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
<div class="top">
    <h1>الفروع</h1>
    <a class="btn" href="{{ route('agent.download') }}">⬇ تنزيل الوكيل <span class="ltr small">v{{ $agentVersion }}</span></a>
    <a class="btn" href="{{ route('agent.guide') }}" target="_blank" rel="noopener">دليل التركيب PDF</a>
    <form method="post" action="{{ route('branches.install-code') }}">
        @csrf
        <button class="primary" type="submit">كود تركيب جديد</button>
    </form>
</div>

@if (session('install_code'))
    <div class="code-box">
        <div class="muted small">كود التركيب — يُستعمل مرة واحدة خلال 24 ساعة. لن يظهر مرة أخرى:</div>
        <div class="code">{{ session('install_code') }}</div>
        <div class="small muted">
            على سيرفر Gescomm في الفرع الجديد: فكّ ضغط «تنزيل الوكيل»، ثم شغّل <span class="ltr">install.ps1</span> كأدمين، وأدخل اسم الفرع وهذا الكود.
            عنوان الـAPI: <span class="ltr">{{ url('/api') }}</span> — التفاصيل في «دليل التركيب».
        </div>
    </div>
@endif

<div class="card">
    @if ($branches->isEmpty())
        <p class="muted">لا يوجد أي فرع بعد. نزّل الوكيل، أنشئ كود تركيب، ثم ركّب الوكيل على سيرفر الفرع (راجع «دليل التركيب»).</p>
    @else
        <table>
            <thead>
            <tr>
                <th>الاسم</th>
                <th>الحالة</th>
                <th>آخر مزامنة</th>
                <th class="hide-sm">السلع</th>
                <th class="hide-sm">آخر كتابة للقاعدة</th>
                <th class="hide-sm">الجهاز</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($branches as $b)
                @php($dbStale = $b->db_file_mtime && $b->db_file_mtime->lt(now()->subDay()))
                <tr class="{{ $b->active ? '' : 'off' }}">
                    <td>
                        <form class="rename" method="post" action="{{ route('branches.update', $b) }}">
                            @csrf @method('patch')
                            <input type="text" name="name" value="{{ $b->name }}" maxlength="100" required>
                            <button type="submit">حفظ</button>
                        </form>
                    </td>
                    <td>
                        @if ($b->is_main)
                            <span class="pill ok" title="أسعاره هي التي تظهر في البحث">رئيسي</span>
                        @endif
                        @if (! $b->active)
                            <span class="pill">معطّل</span>
                        @elseif ($b->isOnline())
                            <span class="pill ok">متصل</span>
                        @else
                            <span class="pill warn">غير متصل</span>
                            <div class="muted small">{{ $b->last_seen_at?->diffForHumans() ?? 'أبداً' }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="sync">
                            <span class="small last-sync">{{ $b->last_sync_at?->diffForHumans() ?? '—' }}</span>
                            @if ($b->active)
                                <button type="button" data-sync="{{ route('branches.sync', $b) }}" title="يطلب من وكيل الفرع إرسال كل المخزون الآن">⟳ مزامنة الآن</button>
                            @endif
                            <span class="sync-msg muted"></span>
                        </div>
                    </td>
                    <td class="hide-sm"><span class="num items-count">{{ number_format($b->items_count, 0, ',', ' ') }}</span></td>
                    <td class="hide-sm small">
                        @if ($b->db_file_mtime)
                            <span class="{{ $dbStale ? 'pill warn' : '' }}" title="{{ $b->db_file_mtime->format('d/m/Y H:i') }}">{{ $b->db_file_mtime->diffForHumans() }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="hide-sm small muted"><span class="ltr">{{ $b->hostname }}</span>@if ($b->agent_version)<br><span class="ltr">v{{ $b->agent_version }}</span>@endif</td>
                    <td>
                        <div class="actions">
                            <form method="post" action="{{ route('branches.main', $b) }}">
                                @csrf
                                <button type="submit" title="أسعار الفرع الرئيسي تظهر في البحث، والمعاينة تُبرز الفروع التي سعرها مختلف">
                                    {{ $b->is_main ? 'إلغاء الرئيسي' : 'اجعله رئيسياً' }}
                                </button>
                            </form>
                            <form method="post" action="{{ route('branches.update', $b) }}">
                                @csrf @method('patch')
                                <input type="hidden" name="active" value="{{ $b->active ? 0 : 1 }}">
                                <button type="submit">{{ $b->active ? 'تعطيل' : 'تفعيل' }}</button>
                            </form>
                            <form method="post" action="{{ route('branches.destroy', $b) }}"
                                  onsubmit="return confirm('حذف الفرع «{{ $b->name }}» وكل مخزونه؟ وكيله سيُرفض بعد ذلك.')">
                                @csrf @method('delete')
                                <button class="danger" type="submit">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="muted small">
            «مزامنة الآن» = يطلب من وكيل الفرع إرسال كل المخزون فوراً، بدل انتظار المزامنة التلقائية (كل 5 دقائق). يحتاج الوكيل 1.0.4 أو أحدث.
            «آخر كتابة للقاعدة» = آخر تعديل لملف قاعدة Gescomm في الفرع. إذا تجاوز يوماً كاملاً في أيام العمل، ربما يعمل البرنامج على ملف آخر (بعد استرجاع نسخة) — تحقّق من مسار القاعدة في إعداد الوكيل.
            الفرع المعطّل يُرفض وكيله ولا يظهر في البحث.
            «الفرع الرئيسي» = مرجع الأسعار: سعره يظهر في سطر كل صنف في البحث، والمعاينة تعرض سعر كل فرع وتُبرز المختلف عنه. يحتاج الوكيل 1.0.5 أو أحدث (الأسعار).
        </p>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const MAX_WAIT_MS = 130000;
    const texts = {
        pending: 'في انتظار الوكيل…', taken: 'الوكيل يرسل المخزون…',
        done: 'تمت المزامنة ✓', error: 'فشلت المزامنة في الفرع', timeout: 'الوكيل لم يرد — هل الفرع متصل؟',
    };

    document.querySelectorAll('button[data-sync]').forEach(btn => btn.addEventListener('click', async () => {
        const msg = btn.parentElement.querySelector('.sync-msg');
        const show = (text, cls) => { msg.textContent = text; msg.className = 'sync-msg ' + (cls || 'muted'); };
        btn.disabled = true;
        show(texts.pending, 'wait muted');
        const row = btn.closest('tr');
        try {
            // batch choisi ici : rejouee apres une coupure, la requete ne cree pas une seconde demande
            const res = await fetchRetry(btn.dataset.sync, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({batch: newId()}),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
            const statusUrl = btn.dataset.sync + '/' + data.job;
            const started = Date.now();
            const tick = async () => {
                let s;
                try {
                    const r = await fetchRetry(statusUrl, {headers: {Accept: 'application/json'}});
                    s = await r.json();
                } catch (e) {
                    // coupure passagere : on reessaie au prochain tour, sans alarmer l'utilisateur
                    if (Date.now() - started < MAX_WAIT_MS) { setTimeout(tick, 3000); return; }
                    s = {status: 'timeout'};
                }
                if (s.status === 'done') {
                    // mise a jour de la ligne sur place, sans recharger la page
                    row.querySelector('.last-sync').textContent = s.last_sync || '—';
                    const count = row.querySelector('.items-count');
                    if (count) count.textContent = Number(s.items_count || 0).toLocaleString('fr-FR');
                    show(texts.done, 'pill ok');
                    btn.disabled = false;
                    return;
                }
                if (s.status === 'error' || s.status === 'timeout' || Date.now() - started > MAX_WAIT_MS) {
                    show(texts[s.status] || texts.timeout, 'pill warn');
                    btn.disabled = false;
                    return;
                }
                show(texts[s.status] || texts.pending, 'wait muted');
                setTimeout(tick, 2000);
            };
            setTimeout(tick, 1500);
        } catch (e) {
            show('تعذّر إرسال الطلب (' + e.message + ')', 'pill warn');
            btn.disabled = false;
        }
    }));
})();
</script>
@endpush
