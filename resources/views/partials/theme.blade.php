{{-- Couleurs claires / sombres. data-theme sur <html> : "light", "dark", ou absent = suivre le reglage de Windows / du telephone. --}}
{{--
    Palette "acier galvanise" : fond zinc, encre acier, accent bleu-vert galvanise (proche du bleu de Gescomm).
    --tag = l'etiquette de rayon jaune, reservee a l'etiquette de la page d'un article (un seul element fort).
    Etats du stock, memes sens que la liste des articles de Gescomm : --zero = stock 0 (rouge), --negative = stock < 0 (jaune),
    --low = sous le seuil d'alerte (vert), --over = au-dessus du stock max (orange). Chaque etat : couleur du texte + fond.
--}}
<style>
    :root {
        color-scheme: light;
        --bg: #edf1f3; --card: #ffffff; --text: #17222b; --muted: #5b6975; --border: #d6dde2;
        --accent: #146c84; --on-accent: #ffffff; --row-hover: #eef3f6; --accent-soft: #e1eef2;
        --tile: #ffffff; --shadow: 0 1px 2px rgba(23, 34, 43, .06), 0 6px 18px rgba(23, 34, 43, .07);
        --ok: #17773f; --warn: #8a5d00; --bad: #c0392b; --ok-bg: #dcf2e3; --warn-bg: #fff1c2; --bad-bg: #fde6e3;
        --tag: #f4c542; --tag-ink: #2b2203; --tag-edge: #d9a91c;
        --zero: #b3261e; --zero-bg: #fde3df; --negative: #7a5600; --negative-bg: #ffe9a3;
        --low: #17773f; --low-bg: #dcf2e3; --over: #a84a06; --over-bg: #ffe6d1;
        --bar: #9fc4d0; --bar-strong: #146c84;
        /* couleur de chaque branche (Branch::HUES) : hsl(--bh saturation clarte) ; fond, fond au survol, pastille */
        --tint-s: 70%; --tint-l: 95.5%; --tint-hover-l: 91.5%; --tint-dot-l: 52%;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
            color-scheme: dark;
            --bg: #10161b; --card: #182027; --text: #e3e9ee; --muted: #8f9ca8; --border: #27323b;
            --accent: #5bb8cf; --on-accent: #062029; --row-hover: #1f2931; --accent-soft: #15303a;
            --tile: #1f2931; --shadow: 0 1px 2px rgba(0, 0, 0, .35), 0 8px 22px rgba(0, 0, 0, .3);
            --ok: #4cc27a; --warn: #e0b13a; --bad: #f2735f; --ok-bg: #11301e; --warn-bg: #3a2e08; --bad-bg: #3d1915;
            --tag: #e0b13a; --tag-ink: #1d1602; --tag-edge: #b88a14;
            --zero: #ff8a7a; --zero-bg: #45191a; --negative: #f3cc5c; --negative-bg: #3d3008;
            --low: #4cc27a; --low-bg: #11301e; --over: #ffa463; --over-bg: #43230b;
            --bar: #2d5563; --bar-strong: #5bb8cf;
            --tint-s: 18%; --tint-l: 16%; --tint-hover-l: 19.5%; --tint-dot-l: 62%;
        }
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #10161b; --card: #182027; --text: #e3e9ee; --muted: #8f9ca8; --border: #27323b;
        --accent: #5bb8cf; --on-accent: #062029; --row-hover: #1f2931; --accent-soft: #15303a;
        --tile: #1f2931; --shadow: 0 1px 2px rgba(0, 0, 0, .35), 0 8px 22px rgba(0, 0, 0, .3);
        --ok: #4cc27a; --warn: #e0b13a; --bad: #f2735f; --ok-bg: #11301e; --warn-bg: #3a2e08; --bad-bg: #3d1915;
        --tag: #e0b13a; --tag-ink: #1d1602; --tag-edge: #b88a14;
        --zero: #ff8a7a; --zero-bg: #45191a; --negative: #f3cc5c; --negative-bg: #3d3008;
        --low: #4cc27a; --low-bg: #11301e; --over: #ffa463; --over-bg: #43230b;
        --bar: #2d5563; --bar-strong: #5bb8cf;
        --tint-s: 18%; --tint-l: 16%; --tint-hover-l: 19.5%; --tint-dot-l: 62%;
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
