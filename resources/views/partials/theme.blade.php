{{-- Couleurs claires / sombres. data-theme sur <html> : "light", "dark", ou absent = suivre le reglage de Windows / du telephone. --}}
{{-- Accent violet facon Odoo ; --accent-soft = fond teinte (accueil, connexion), --tile = fond des icones d'applications. --}}
<style>
    :root {
        color-scheme: light;
        --bg: #f4f3f6; --card: #ffffff; --text: #1f1d24; --muted: #6b6775; --border: #e4e1e8;
        --accent: #714b67; --on-accent: #ffffff; --row-hover: #f3f0f4; --accent-soft: #efe6ee;
        --tile: #ffffff; --shadow: 0 1px 2px rgba(31, 29, 36, .06), 0 6px 18px rgba(31, 29, 36, .07);
        --ok: #1a7f37; --warn: #9a6700; --bad: #cf222e; --ok-bg: #dafbe1; --warn-bg: #fff8c5; --bad-bg: #ffebe9;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
            color-scheme: dark;
            --bg: #17161c; --card: #211f27; --text: #ebe8f0; --muted: #9c97a6; --border: #34313d;
            --accent: #c792bb; --on-accent: #1a1219; --row-hover: #2a2731; --accent-soft: #2b2230;
            --tile: #2a2832; --shadow: 0 1px 2px rgba(0, 0, 0, .35), 0 8px 22px rgba(0, 0, 0, .3);
            --ok: #3fb950; --warn: #d29922; --bad: #f85149; --ok-bg: #12301c; --warn-bg: #3a2e0a; --bad-bg: #3d1517;
        }
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #17161c; --card: #211f27; --text: #ebe8f0; --muted: #9c97a6; --border: #34313d;
        --accent: #c792bb; --on-accent: #1a1219; --row-hover: #2a2731; --accent-soft: #2b2230;
        --tile: #2a2832; --shadow: 0 1px 2px rgba(0, 0, 0, .35), 0 8px 22px rgba(0, 0, 0, .3);
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
