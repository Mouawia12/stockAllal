{{-- Reconnexion automatique sur ligne instable : public/sw.js redemande une page qui ne repond pas, puis affiche offline.html. --}}
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register(@json(asset('sw.js')), {scope: '/'}).catch(() => {}));
    }
</script>
