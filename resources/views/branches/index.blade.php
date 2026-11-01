@extends('layouts.app')

@section('title', 'الفروع — مخزون الفروع')

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    .code-box { border: 2px dashed var(--accent); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; background: var(--card); }
    .code-box .code { font: 600 26px/1.3 Consolas, "Courier New", monospace; letter-spacing: 2px; direction: ltr; unicode-bidi: isolate; }
    .rename { display: flex; gap: 6px; }
    .rename input { width: 170px; padding: 5px 8px; }
    .rename button { padding: 5px 10px; }
    .actions { display: flex; gap: 6px; }
    .actions button { padding: 5px 10px; }
    tr.off td { opacity: .55; }
</style>
@endpush

@section('content')
<div class="top">
    <h1>الفروع</h1>
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
            على سيرفر الفرع الجديد، شغّل <span class="ltr">install.ps1</span> كأدمين من مجلد <span class="ltr">agent</span>، وأدخل اسم الفرع وهذا الكود.
            عنوان الـAPI: <span class="ltr">{{ url('/api') }}</span>
        </div>
    </div>
@endif

<div class="card">
    @if ($branches->isEmpty())
        <p class="muted">لا يوجد أي فرع بعد. أنشئ كود تركيب، ثم ركّب الوكيل على سيرفر الفرع.</p>
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
                        @if (! $b->active)
                            <span class="pill">معطّل</span>
                        @elseif ($b->isOnline())
                            <span class="pill ok">متصل</span>
                        @else
                            <span class="pill warn">غير متصل</span>
                            <div class="muted small">{{ $b->last_seen_at?->diffForHumans() ?? 'أبداً' }}</div>
                        @endif
                    </td>
                    <td class="small">{{ $b->last_sync_at?->diffForHumans() ?? '—' }}</td>
                    <td class="hide-sm"><span class="num">{{ number_format($b->items_count, 0, ',', ' ') }}</span></td>
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
            «آخر كتابة للقاعدة» = آخر تعديل لملف قاعدة Gescomm في الفرع. إذا تجاوز يوماً كاملاً في أيام العمل، ربما يعمل البرنامج على ملف آخر (بعد استرجاع نسخة) — تحقّق من مسار القاعدة في إعداد الوكيل.
            الفرع المعطّل يُرفض وكيله ولا يظهر في البحث.
        </p>
    @endif
</div>
@endsection
