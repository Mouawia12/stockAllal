@extends('errors.layout')

@section('title', 'غير مسموح')
@section('heading', 'هذه الصفحة للمدير فقط')
@section('message', 'حسابك يسمح بالبحث في المخزون فقط. إذا كنت تحتاج صلاحيات أكثر، اطلبها من المدير.')
@section('actions')
    <a class="btn primary" href="{{ url('/') }}">الرجوع إلى التطبيقات</a>
@endsection
