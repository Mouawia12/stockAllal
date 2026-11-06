{{--
    Les pages d'impression (resources/views/print) : une feuille blanche, a l'ecran comme sur le papier, jamais de theme
    de nuit ; une barre au-dessus, qui ne s'imprime pas (imprimer, fermer, langue, reglages de la page : @section('tools')).
    Encre noire seulement : le papier n'imprime ni les fonds ni les couleurs, et rouge / jaune disent deja "stock 0 / negatif".
    La page donne : $pageTitle (rappele en bas de chaque page), $back (ou revenir), $pageSize (A4, A5, A4 landscape).
    ?auto=1 : ouvre la fenetre d'impression au chargement (le bouton d'un bon).
--}}
@php
    $pageSize ??= 'A4';
    $pageTitle ??= __('common.app_name');
    $locale = app()->getLocale();
    $rtl = config('app.locales')[$locale]['dir'] === 'rtl';
    // le titre dans le CSS de @page : une chaine CSS, jamais du HTML (un nom de branche vient de l'agent)
    $cssTitle = '"'.str_replace(['\\', '"', '<', "\n", "\r"], ['\\\\', '\\"', '\\3C ', ' ', ' '], $pageTitle).'"';
    $narrow = str_starts_with($pageSize, 'A5');
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @include('partials.favicon')
    @include('partials.fonts')
    <style>
        @page {
            size: {{ $pageSize }};
            margin: {{ $narrow ? '8mm 8mm 11mm' : '11mm 12mm 13mm' }};
            @bottom-{{ $rtl ? 'right' : 'left' }} { content: {!! $cssTitle !!}; font: 7.5pt "Segoe UI", Tahoma, Arial, sans-serif; color: #444; }
            @bottom-{{ $rtl ? 'left' : 'right' }} { content: counter(page) " / " counter(pages); font: 7.5pt "Segoe UI", Tahoma, Arial, sans-serif; color: #444; }
        }
        :root { color-scheme: light; --fs: {{ $narrow ? '8pt' : '9.5pt' }}; --hair: #b9b9b9; --soft: #444; --tool: #146c84; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; background: #d9dfe3; color: #000; font: var(--fs)/1.42 var(--font); }
        p, h1, h2, h3, dl, dd { margin: 0; }
        a { color: inherit; text-decoration: none; }
        .num { direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        .code { font-family: var(--font-code); font-weight: 600; font-size: 1.1em; letter-spacing: .02em; }
        .soft { color: var(--soft); }
        .small { font-size: .86em; }

        /* la feuille : a l'ecran sa vraie largeur ; sur le papier, les marges de @page */
        .sheet { width: {{ str_contains($pageSize, 'landscape') ? '297mm' : ($narrow ? '148mm' : '210mm') }}; min-height: {{ str_contains($pageSize, 'landscape') ? '210mm' : ($narrow ? '210mm' : '297mm') }};
            margin: 16px auto 48px; padding: {{ $narrow ? '8mm' : '11mm 12mm 13mm' }}; background: #fff; box-shadow: 0 1px 3px rgba(0, 0, 0, .25), 0 10px 30px rgba(0, 0, 0, .14); }

        /* en-tete : la societe au debut, la piece a la fin, un trait franc dessous */
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; padding-bottom: 3mm; border-bottom: 1.5pt solid #000; }
        .lh { min-width: 0; }
        .lh-name { display: block; font-size: 1.5em; font-weight: 700; line-height: 1.2; }
        .lh-line { display: block; font-size: .9em; color: #222; }
        .doc-title { text-align: end; flex: none; max-width: 55%; }
        .doc-kind { font-size: 1.2em; font-weight: 600; }
        .doc-no { font-family: var(--font-code); font-weight: 700; font-size: 3em; line-height: 1; letter-spacing: .01em; }
        .doc-sub { font-size: .92em; }

        /* tableaux : des traits fins, pas de fond, la ligne ne se coupe jamais entre deux pages */
        table.grid { width: 100%; border-collapse: collapse; margin-top: 3.5mm; }
        table.grid th { text-align: start; font-weight: 600; font-size: .9em; padding: 1.2mm 1.5mm; border-top: 1pt solid #000; border-bottom: 1pt solid #000; white-space: nowrap; vertical-align: bottom; }
        /* break-word : un mot trop long se coupe, sans que les colonnes etroites (un numero, "Espece") se coupent lettre par lettre */
        table.grid td { padding: .7mm 1.5mm; line-height: 1.28; border-bottom: .5pt solid var(--hair); vertical-align: top; overflow-wrap: break-word; }
        table.grid .n { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.grid tr { break-inside: avoid; }
        /* un groupe (un jour) : son titre reste avec sa premiere ligne */
        table.grid tr.group th { border-top: 0; padding-top: 3mm; font-size: 1em; white-space: normal; break-after: avoid; }
        table.grid tr.group .group-in { display: flex; justify-content: space-between; gap: 6mm; }
        table.grid tr.sum td { border-top: 1pt solid #000; border-bottom: 0; font-weight: 700; }
        .sec { font-size: 1.1em; font-weight: 700; margin-top: 5mm; }
        .tag { font-size: .82em; font-weight: 600; border: .6pt solid #000; border-radius: 2pt; padding: 0 1.2mm; white-space: nowrap; }
        .note { margin-top: 2.5mm; }
        .legal { display: flex; flex-wrap: wrap; gap: 0 4mm; font-size: .86em; }
        .empty { margin-top: 10mm; text-align: center; color: var(--soft); }
        .doc-foot { margin-top: 5mm; padding-top: 1.5mm; border-top: .5pt solid var(--hair); display: flex; justify-content: space-between; gap: 6mm; font-size: .84em; color: #222; break-inside: avoid; }
        .doc-foot .lines { min-width: 0; }
        .doc-foot .lines span { display: block; }
        .doc-foot .stamp { flex: none; margin-inline-start: auto; }

        /* la barre : jamais sur le papier */
        .tools { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; padding: 8px 14px; background: #fff; border-bottom: 1px solid #c7ced3; font-size: 13.5px; color: #17222b; }
        .tools .grow { flex: 1; }
        .tools button, .tools .btn { font: inherit; display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid #c7ced3; border-radius: 8px; background: #fff; color: inherit; cursor: pointer; }
        .tools button:hover, .tools .btn:hover { background: #eef3f6; }
        .tools .go { background: var(--tool); border-color: var(--tool); color: #fff; font-weight: 600; }
        .tools .go:hover { background: var(--tool); filter: brightness(1.1); }
        .tools .go svg { width: 18px; height: 18px; }
        .tools kbd { font: 600 11px/1.5 var(--font-code); letter-spacing: .04em; border: 1px solid rgba(255, 255, 255, .55); border-radius: 4px; padding: 0 5px; }
        .tools :focus-visible { outline: 2px solid var(--tool); outline-offset: 2px; }
        .choice { display: inline-flex; align-items: center; gap: 6px; color: #5b6975; }
        .choice .seg { display: inline-flex; gap: 2px; padding: 2px; border: 1px solid #c7ced3; border-radius: 8px; background: #fff; }
        .choice a { padding: 2px 10px; border-radius: 6px; color: #17222b; white-space: nowrap; }
        .choice a:hover { background: #eef3f6; }
        .choice a.on { background: #e1eef2; color: var(--tool); font-weight: 600; }
        .tools form { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0; color: #5b6975; }
        .tools input[type=date] { font: inherit; padding: 3px 6px; border: 1px solid #c7ced3; border-radius: 8px; background: #fff; color: #17222b; }
        .tools-note { flex-basis: 100%; margin: 0; color: #5b6975; font-size: 12.5px; }

        @media screen and (max-width: 860px) {
            .sheet { width: auto; min-height: 0; margin: 0; padding: 16px; box-shadow: none; overflow-x: auto; }
            .doc-title { max-width: none; }
            body { background: #fff; }
        }
        @media print {
            body { background: none; }
            .noprint { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        }
    </style>
    @stack('head')
</head>
<body>
<div class="tools noprint">
    <button type="button" class="go" id="print-now" title="Alt+P" autofocus>@include('partials.app-icon', ['name' => 'print']) {{ __('print.print') }}</button>
    <a class="btn" id="print-close" href="{{ $back ?? route('home') }}" title="Échap">{{ __('print.close') }}</a>
    @yield('tools')
    <span class="grow"></span>
    @php($langs = collect(config('app.locales'))->map(fn ($l) => $l['short'])->all())
    @include('print._choice', ['label' => __('print.language'), 'param' => 'lang', 'current' => $locale, 'options' => $langs])
    @hasSection('tools-note')<p class="tools-note">@yield('tools-note')</p>@endif
</div>
<main class="sheet">
    @yield('content')
</main>
<script>
(() => {
    const print = () => window.print();
    document.getElementById('print-now').addEventListener('click', print);
    // une page ouverte dans un nouvel onglet se ferme ; sinon (la langue a change l'historique), retour a la page d'origine
    const close = () => { window.close(); setTimeout(() => { location.href = document.getElementById('print-close').href; }, 150); };
    document.getElementById('print-close').addEventListener('click', e => { e.preventDefault(); close(); });
    document.addEventListener('keydown', e => {
        if (e.altKey && e.code === 'KeyP') { e.preventDefault(); print(); }
        if (e.key === 'Escape' && !e.target.closest?.('input, select, textarea')) close();
    });
    @if (request()->boolean('auto'))
        // ?auto=1 : une seule fois (retire de l'adresse), une fois les polices arrivees, ou sans elles sur une ligne lente
        history.replaceState(null, '', location.href.replace(/([?&])auto=1&?/, '$1').replace(/[?&]$/, ''));
        addEventListener('load', () => setTimeout(() => Promise.race([document.fonts.ready, new Promise(done => setTimeout(done, 1500))]).then(print), 200));
    @endif
})();
</script>
</body>
</html>
