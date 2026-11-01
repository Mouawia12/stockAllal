{{-- Maintenance (php artisan down, pendant une mise a jour) : la page se recharge seule jusqu'au retour du site. --}}
@extends('errors.layout')

@section('title', 'تحديث قصير')
@section('heading', 'تحديث قصير للنظام')
@section('message', 'نعود خلال لحظات. لا تحتاج أن تفعل شيئاً: الصفحة تُعاد تلقائياً.')
@section('actions')
    <button class="btn primary" type="button" onclick="location.reload()">إعادة المحاولة الآن</button>
@endsection
@section('after')
    <p class="muted" id="retry">إعادة المحاولة بعد <span id="sec">15</span> ثانية…</p>
    <script>
        (() => {
            let s = 15;
            const el = document.getElementById('sec');
            setInterval(() => { s -= 1; if (s <= 0) location.reload(); else el.textContent = s; }, 1000);
        })();
    </script>
@endsection
