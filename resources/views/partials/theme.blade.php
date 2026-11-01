{{-- Couleurs claires / sombres. data-theme sur <html> : "light", "dark", ou absent = suivre le reglage de Windows / du telephone. --}}
<style>
    :root {
        color-scheme: light;
        --bg: #f5f6f8; --card: #ffffff; --text: #1d2228; --muted: #67707a; --border: #e1e4e8;
        --accent: #1f6feb; --on-accent: #ffffff; --row-hover: #f0f3f6;
        --ok: #1a7f37; --warn: #9a6700; --bad: #cf222e; --ok-bg: #dafbe1; --warn-bg: #fff8c5; --bad-bg: #ffebe9;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
            color-scheme: dark;
            --bg: #0f1216; --card: #171b21; --text: #e6edf3; --muted: #8b949e; --border: #2d333b;
            --accent: #4493f8; --on-accent: #0f1216; --row-hover: #1f252d;
            --ok: #3fb950; --warn: #d29922; --bad: #f85149; --ok-bg: #12301c; --warn-bg: #3a2e0a; --bad-bg: #3d1517;
        }
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #0f1216; --card: #171b21; --text: #e6edf3; --muted: #8b949e; --border: #2d333b;
        --accent: #4493f8; --on-accent: #0f1216; --row-hover: #1f252d;
        --ok: #3fb950; --warn: #d29922; --bad: #f85149; --ok-bg: #12301c; --warn-bg: #3a2e0a; --bad-bg: #3d1517;
    }
</style>
<script>
    // avant l'affichage de la page : pas de flash de la mauvaise couleur
    (function () {
        try {
            var t = localStorage.getItem('theme');
            if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
</script>
