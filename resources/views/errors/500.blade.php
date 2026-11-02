@extends('errors.layout')

@section('title', __('errors.server.title'))
@section('heading', __('errors.server.heading'))
@section('message', __('errors.server.message'))
@section('actions')
    <button class="btn primary" type="button" onclick="location.reload()">{{ __('errors.server.retry') }}</button>
    <a class="btn" href="{{ url('/') }}">{{ __('errors.server.home') }}</a>
@endsection
