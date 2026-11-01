@extends('layouts.app')

@section('title', "تعديل {$user->username} — مخزون الفروع")

@push('head')
<style>
    .edit-user { max-width: 760px; }
    .edit-user h1 { font-size: 20px; margin: 0 0 14px; }
    .edit-user .bar { display: flex; gap: 8px; margin-top: 16px; }
</style>
@endpush

@section('content')
<form class="card edit-user" method="post" action="{{ route('users.update', $user) }}">
    @csrf @method('put')
    <h1>تعديل الحساب <span class="ltr">{{ $user->username }}</span></h1>
    @include('users.form', ['user' => $user])
    @if ($user->is(auth()->user()))
        <p class="muted small">هذا حسابك: لا يمكن إزالة صلاحية المدير عنه.</p>
    @endif
    <div class="bar">
        <button class="primary" type="submit">حفظ</button>
        <a class="btn" href="{{ route('users.index') }}">رجوع</a>
    </div>
</form>
@endsection
