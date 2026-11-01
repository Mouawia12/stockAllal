@extends('layouts.app')

@section('title', 'المستخدمون — مخزون الفروع')

@push('head')
<style>
    .page-title { font-size: 20px; margin: 0 0 16px; }
    .new-user { margin-bottom: 16px; }
    .new-user h2 { font-size: 16px; margin: 0 0 12px; }
    .new-user .submit { margin-top: 14px; }
    .actions { display: flex; gap: 6px; justify-content: flex-end; }
    .actions .btn, .actions button { padding: 4px 12px; }
</style>
@endpush

@section('content')
<h1 class="page-title">المستخدمون</h1>

<form class="card new-user" method="post" action="{{ route('users.store') }}">
    @csrf
    <h2>حساب جديد</h2>
    @include('users.form', ['user' => null])
    <div class="submit"><button class="primary" type="submit">إنشاء الحساب</button></div>
</form>

<div class="card">
    <table>
        <thead>
        <tr>
            <th>الاسم</th>
            <th>اسم المستخدم</th>
            <th>الصلاحية</th>
            <th class="hide-sm">أُنشئ</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td>{{ $u->name }} @if ($u->is(auth()->user()))<span class="muted small">(أنت)</span>@endif</td>
                <td><span class="ltr">{{ $u->username }}</span></td>
                <td>
                    @if ($u->is_admin)
                        <span class="pill ok">مدير</span>
                    @else
                        <span class="pill">عامل — المخزون فقط</span>
                    @endif
                </td>
                <td class="hide-sm small muted">{{ $u->created_at?->diffForHumans() }}</td>
                <td>
                    <div class="actions">
                        <a class="btn" href="{{ route('users.edit', $u) }}">تعديل</a>
                        @unless ($u->is(auth()->user()))
                            <form method="post" action="{{ route('users.destroy', $u) }}"
                                  onsubmit="return confirm('حذف حساب «{{ $u->username }}»؟ لن يستطيع الدخول بعد ذلك.')">
                                @csrf @method('delete')
                                <button class="danger" type="submit">حذف</button>
                            </form>
                        @endunless
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="muted small">
        <b>العامل</b> يرى البحث في المخزون ونافذة الفروع ويضغط «تحديث الآن»، ويغيّر كلمة سره من «حسابي». <b>المدير</b> يرى كل شيء: الفروع، تنزيل الوكيل، والحسابات.
        لا يمكنك حذف حسابك ولا إزالة صلاحية المدير عنه، حتى يبقى دائماً مدير واحد على الأقل.
    </p>
</div>
@endsection
