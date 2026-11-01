@extends('layouts.app')

@section('title', 'حسابي — مخزون الفروع')

@push('head')
<style>
    .account { max-width: 460px; }
    .account h1 { font-size: 20px; margin: 0 0 16px; }
    .account label { display: block; font-size: 13px; color: var(--muted); margin: 14px 0 4px; }
    .account input[type=text], .account input[type=password] { width: 100%; }
    .account hr { border: 0; border-top: 1px solid var(--border); margin: 20px 0 6px; }
    .account button { margin-top: 20px; }
</style>
@endpush

@section('content')
<form class="card account" method="post" action="{{ route('account.update') }}">
    @csrf @method('put')
    <h1>حسابي</h1>

    <label for="name">الاسم الظاهر</label>
    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="100" required>

    <label for="username">اسم المستخدم (للدخول)</label>
    <input id="username" type="text" name="username" value="{{ old('username', $user->username) }}" maxlength="50" required dir="ltr" autocomplete="username">

    <hr>
    <label for="password">كلمة السر الجديدة <span class="small">(اتركها فارغة إذا لا تريد تغييرها)</span></label>
    <input id="password" type="password" name="password" minlength="8" dir="ltr" autocomplete="new-password">

    <label for="password_confirmation">تأكيد كلمة السر الجديدة</label>
    <input id="password_confirmation" type="password" name="password_confirmation" dir="ltr" autocomplete="new-password">

    <hr>
    <label for="current_password">كلمة السر الحالية <span class="small">(مطلوبة لحفظ أي تغيير)</span></label>
    <input id="current_password" type="password" name="current_password" required dir="ltr" autocomplete="current-password">

    <button class="primary" type="submit">حفظ</button>
</form>
@endsection
