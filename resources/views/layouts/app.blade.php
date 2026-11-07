<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('app.locales')[app()->getLocale()]['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('common.app_name'))</title>
    @include('partials.favicon')
    @include('partials.fonts')
    @include('partials.theme')
    {{-- bandeau des touches masque (partials/keys) : avant l'affichage, pour qu'il n'apparaisse pas une fraction de seconde --}}
    <script>try { if (localStorage.getItem('keys-bar') === 'off') document.documentElement.dataset.keys = 'off'; } catch (e) {}</script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 var(--font); }
        a { color: var(--accent); text-decoration: none; }
        header { background: var(--card); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 5; }
        /* barre du haut facon Odoo : au debut l'application et ses menus, au bout seulement la branche, la recherche et l'avatar
           (langue, apparence, حسابي et خروج sont dans le menu de l'avatar, partials/user-menu) */
        header .in { max-width: 1100px; margin: 0 auto; padding: 6px 16px; min-height: 52px; display: flex; align-items: center; gap: 6px; }
        header .launcher { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; color: var(--muted); flex: none; }
        header .launcher:hover { background: var(--row-hover); color: var(--text); }
        header .launcher svg { width: 20px; height: 20px; }
        header .app-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 16px; color: var(--text); white-space: nowrap; margin-inline-end: 10px; }
        header .app-title svg { width: 26px; height: 26px; display: block; }
        header nav { display: flex; gap: 2px; flex: 1; overflow-x: auto; }
        header nav a { color: var(--muted); padding: 6px 10px; border-radius: 8px; white-space: nowrap; }
        header nav a:hover { background: var(--row-hover); color: var(--text); }
        header nav a.on { color: var(--text); background: var(--accent-soft); }
        header .tools { display: flex; align-items: center; gap: 4px; flex: none; }
        header .icon-btn { display: grid; place-items: center; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%; background: none; color: var(--muted); }
        header .icon-btn:hover { background: var(--row-hover); color: var(--text); }
        header .icon-btn svg { width: 19px; height: 19px; }
        .avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: var(--on-accent); display: grid; place-items: center; font-weight: 700; font-size: 14px; flex: none; }
        /* menus deroulants de la barre (branche, compte) : details.drop + .drop-menu, comportement dans le script du bas */
        .drop { position: relative; flex: none; }
        .drop summary { list-style: none; cursor: pointer; }
        .drop summary::-webkit-details-marker { display: none; }
        .drop-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 220px; background: var(--card); border: 1px solid var(--border);
            border-radius: 12px; box-shadow: var(--shadow); padding: 6px; display: flex; flex-direction: column; z-index: 20; margin: 0; }
        main { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
        /* overflow-x : sur telephone, un tableau large defile dans sa carte au lieu d'elargir toute la page */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        /* start = droite en arabe, gauche en francais / anglais */
        th { text-align: start; font-weight: 600; color: var(--muted); font-size: 13px; padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: var(--row-hover); }
        /* une ligne d'une branche : sa couleur legere (Branch::hue dans --bh), un trait de sa couleur au debut ; .bdot = sa pastille */
        tr.bt > td { background: hsl(var(--bh) var(--tint-s) var(--tint-l)); }
        tr.bt:hover > td { background: hsl(var(--bh) var(--tint-s) var(--tint-hover-l)); }
        tr.bt > td:first-child { border-inline-start: 3px solid hsl(var(--bh) 55% var(--tint-dot-l)); }
        .bdot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; flex: none; vertical-align: middle; background: hsl(var(--bh) 55% var(--tint-dot-l)); }
        input[type=text], input[type=search], input[type=email], input[type=password] {
            font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; }
        input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
        /* chaque saisie dans son sens : "8*20" ou "820AC" de gauche a droite, l'indication arabe de droite a gauche */
        input[type=search] { unicode-bidi: plaintext; }
        button, .btn { font: inherit; cursor: pointer; border: 1px solid var(--border); background: var(--card); color: var(--text); border-radius: 8px; padding: 7px 14px; }
        button:hover, .btn:hover { background: var(--row-hover); }
        button.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        button.danger { color: var(--bad); }
        button.link { border: 0; background: none; color: var(--muted); padding: 4px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        /* codes d'articles : condense, comme les panneaux d'un depot (partials/fonts) */
        .code { font-family: var(--font-code); font-weight: 600; font-size: 1.1em; letter-spacing: .02em; line-height: 1.2; }
        /* quantite ; ses etats ont les couleurs de Gescomm (App\Support\StockState) : 0 rouge, negatif jaune, alerte vert, max orange */
        .qty { display: inline-block; direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
        .qty.zero, .qty.negative, .qty.low, .qty.over { padding: 0 8px; border-radius: 6px; }
        .qty.zero { color: var(--zero); background: var(--zero-bg); }
        .qty.negative { color: var(--negative); background: var(--negative-bg); }
        .qty.low { color: var(--low); background: var(--low-bg); }
        .qty.over { color: var(--over); background: var(--over-bg); }
        kbd { font: 600 11.5px/1.6 var(--font); border: 1px solid var(--border); border-bottom-width: 2px; border-radius: 5px; padding: 0 6px; background: var(--card); color: var(--muted); white-space: nowrap; direction: ltr; unicode-bidi: isolate; }
        .num { direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .neg { color: var(--bad); }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .pill { display: inline-block; font-size: 12px; padding: 1px 9px; border-radius: 99px; background: var(--row-hover); color: var(--muted); white-space: nowrap; }
        .pill.ok { background: var(--ok-bg); color: var(--ok); }
        .pill.warn { background: var(--warn-bg); color: var(--warn); }
        .pill.bad { background: var(--bad-bg); color: var(--bad); }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; background: var(--ok-bg); color: var(--ok); }
        .errors { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; background: var(--bad-bg); color: var(--bad); }
        .print-btn { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .print-btn svg { width: 17px; height: 17px; flex: none; }
        /* Ctrl+P sur n'importe quelle page : le contenu seul (les documents ont leur propre page, layouts/print) */
        @media print {
            header, .print-btn, .print-drop, .noprint, .modal-bg, .cal, .date-pick, form.search, .live-hint { display: none !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; }
            main { max-width: none; padding: 0; }
            .card { border-color: #bbb; overflow: visible; }
        }
        @media (max-width: 700px) {
            .hide-sm { display: none; }
            td, th { padding: 7px 6px; }
            header .in { flex-wrap: wrap; gap: 4px 6px; }
            header .app-title { flex: 1; min-width: 0; }
            /* deuxieme ligne : les menus de l'application */
            header nav { order: 5; flex-basis: 100%; }
        }
    </style>
    @stack('head')
</head>
<body>
@php
    // application courante (config/apps.php) : son icone, son nom et ses menus dans la barre du haut, facon Odoo
    $user = auth()->user();
    $current = match (true) {
        request()->routeIs('dashboard.*') => 'dashboard',
        request()->routeIs('stock.*', 'merge.*', 'branches.*') => 'stock',
        request()->routeIs('sales.*') => 'sales',
        request()->routeIs('purchases.*') => 'purchases',
        request()->routeIs('customers.*') => 'customers',
        request()->routeIs('suppliers.*') => 'suppliers',
        request()->routeIs('reconcile.*') => 'reconcile',
        request()->routeIs('users.*', 'account.*', 'activity.*', 'ai.*') => 'settings',
        default => null,
    };
    $currentApp = $current ? config("apps.{$current}") : null;
@endphp
<header>
    <div class="in">
        <a class="launcher" href="{{ route('home') }}" data-key="Alt+H" title="{{ __('common.apps_launcher') }}" aria-label="{{ __('common.apps_launcher') }}">@include('partials.app-icon', ['name' => 'grid'])</a>
        @if ($currentApp)
            <a class="app-title" href="{{ route($currentApp['route']) }}">@include('partials.app-icon', ['name' => $current]){{ __("common.apps.{$current}") }}</a>
        @else
            <a class="app-title" href="{{ route('home') }}">@include('partials.app-icon', ['name' => 'logo']){{ __('common.app_name') }}</a>
        @endif
        <nav>
            @if ($current === 'stock')
                <a href="{{ route('stock.index') }}" class="{{ request()->routeIs('stock.*') ? 'on' : '' }}">{{ __('common.nav.search') }}</a>
                @can('merge.manage')
                    <a href="{{ route('merge.index') }}" class="{{ request()->routeIs('merge.*') ? 'on' : '' }}">{{ __('common.nav.merge') }}</a>
                @endcan
                @can('branches.view')
                    <a href="{{ route('branches.index') }}" class="{{ request()->routeIs('branches.*') ? 'on' : '' }}">{{ __('common.nav.branches') }}</a>
                @endcan
            @elseif ($current === 'sales' || $current === 'purchases')
                <a href="{{ route("{$current}.index") }}" class="on">{{ __('documents.title.'.($current === 'sales' ? 'sale' : 'purchase')) }}</a>
            @elseif ($current === 'customers' || $current === 'suppliers')
                <a href="{{ route("{$current}.index") }}" class="on">{{ __('partners.title.'.($current === 'customers' ? 'customer' : 'supplier')) }}</a>
            @elseif ($current === 'settings')
                @if ($user->managesAccounts())
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'on' : '' }}">{{ __('common.nav.users') }}</a>
                @endif
                @can('view-activity')
                    <a href="{{ route('activity.index') }}" class="{{ request()->routeIs('activity.*') ? 'on' : '' }}">{{ __('common.nav.activity') }}</a>
                @endcan
                @can('manage-ai')
                    <a href="{{ route('ai.index') }}" class="{{ request()->routeIs('ai.*') ? 'on' : '' }}">{{ __('common.nav.ai') }}</a>
                @endcan
                <a href="{{ route('account.edit') }}" class="{{ request()->routeIs('account.*') ? 'on' : '' }}">{{ __('common.account') }}</a>
            @endif
        </nav>
        <div class="tools">
            @include('partials.branch-switch')
            <button type="button" class="icon-btn palette-open" data-palette data-key="Ctrl+K F3" data-key-fallback title="{{ __('common.palette.title') }} (Ctrl+K)" aria-label="{{ __('common.palette.title') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            </button>
            @include('partials.user-menu')
        </div>
    </div>
</header>
<main>
    @if (session('weak_password'))
        <div class="errors">{!! __('common.weak_password', ['link' => '<a href="'.route('account.edit').'">'.e(__('common.account')).'</a>']) !!}</div>
    @endif
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    @yield('content')
</main>
<script>
    // Lignes internet instables (jusqu'a une connexion sur deux perdue) : chaque appel a un delai et, sans reponse,
    // il est refait sur une nouvelle connexion. A reserver aux requetes qu'on peut rejouer sans effet de bord.
    // options.signal (facultatif) : annulation par l'appelant, sans nouvel essai (ex. recherche remplacee par une plus recente).
    const LOGIN_PATH = new URL(@json(route('login'))).pathname;
    window.fetchRetry = async (url, options = {}, tries = 3, timeoutMs = 8000) => {
        const outer = options.signal;
        for (let attempt = 1; ; attempt++) {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), timeoutMs);
            const cancel = () => ctrl.abort();
            outer?.addEventListener('abort', cancel);
            let res;
            try {
                if (outer?.aborted) throw new DOMException('annule', 'AbortError');
                res = await fetch(url, {...options, signal: ctrl.signal});
                if (res.status >= 502 && res.status <= 504 && attempt < tries) throw new Error('HTTP ' + res.status);
            } catch (e) {
                if (outer?.aborted) throw new DOMException('annule', 'AbortError');
                if (attempt >= tries) throw (e.name === 'AbortError' ? new Error(@json(__('common.no_response'))) : e);
                await new Promise(r => setTimeout(r, 500 * attempt));
                continue;
            } finally {
                clearTimeout(timer);
                outer?.removeEventListener('abort', cancel);
            }
            // connexion terminee (8 h, ExpireLogin) : la page se recharge et mene a la connexion,
            // au lieu d'une erreur ou de la page de connexion affichee dans les resultats
            if (res.status === 401 || res.status === 419 || (res.redirected && new URL(res.url).pathname === LOGIN_PATH)) {
                location.reload();
                throw new DOMException('session', 'AbortError');
            }
            return res;
        }
    };
    // identifiant choisi par la page : une requete POST rejouee est reconnue par le serveur et n'agit qu'une fois
    window.newId = () => (crypto.randomUUID ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)));

    // menus de la barre du haut (details.drop) : un seul ouvert, un clic ailleurs ou Echap ferme ;
    // ouvert (aussi par sa touche, ex. Alt+B), le clavier est sur l'element choisi, les fleches passent aux autres
    (() => {
        const drops = [...document.querySelectorAll('details.drop')];
        const entries = d => [...d.querySelectorAll('.drop-menu a, .drop-menu button')];
        document.addEventListener('click', e => drops.forEach(d => { if (d.open && !d.contains(e.target)) d.open = false; }));
        drops.forEach(d => d.addEventListener('toggle', () => {
            if (d.open) (d.querySelector('.drop-menu > .on') || entries(d)[0])?.focus();
        }));
        document.addEventListener('keydown', e => {
            const open = drops.find(d => d.open);
            if (!open) return;
            if (e.key === 'Escape') { e.preventDefault(); open.open = false; open.querySelector('summary').focus(); return; }
            if ((e.key !== 'ArrowDown' && e.key !== 'ArrowUp') || !e.target.closest?.('.drop-menu')) return;
            e.preventDefault();
            const list = entries(open);
            list[(list.indexOf(e.target.closest('a, button')) + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length].focus();
        });
    })();
</script>
@include('partials.palette')
@stack('scripts')
@include('partials.keys')
@include('partials.sw')
</body>
</html>
