@extends('errors.layout')

@section('title', __('errors.forbidden.title'))
@section('heading', __('errors.forbidden.heading'))
@section('message', __('errors.forbidden.message'))
@section('actions')
    <a class="btn primary" href="{{ url('/') }}">{{ __('errors.forbidden.back') }}</a>
@endsection
