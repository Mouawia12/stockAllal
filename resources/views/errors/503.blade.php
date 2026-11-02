{{-- Maintenance (php artisan down, pendant une mise a jour) : la page se recharge seule jusqu'au retour du site. --}}
@extends('errors.layout')

@section('title', __('errors.maintenance.title'))
@section('heading', __('errors.maintenance.heading'))
@section('message', __('errors.maintenance.message'))
@section('actions')
    <button class="btn primary" type="button" onclick="location.reload()">{{ __('errors.maintenance.retry_now') }}</button>
@endsection
@section('after')
    <p class="muted" id="retry">{!! __('errors.maintenance.retry_in', ['seconds' => '<span id="sec">15</span>']) !!}</p>
    <script>
        (() => {
            let s = 15;
            const el = document.getElementById('sec');
            setInterval(() => { s -= 1; if (s <= 0) location.reload(); else el.textContent = s; }, 1000);
        })();
    </script>
@endsection
