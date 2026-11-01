@extends('errors.layout')

@section('title', 'خطأ')
@section('heading', 'حدث خطأ غير متوقع')
@section('message', 'سُجّل الخطأ لدى المطوّر. أعد المحاولة بعد لحظة، وإذا تكرّر فأخبر المدير.')
@section('actions')
    <button class="btn primary" type="button" onclick="location.reload()">إعادة المحاولة</button>
    <a class="btn" href="{{ url('/') }}">الصفحة الرئيسية</a>
@endsection
