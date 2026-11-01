<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'مخزون الفروع')</title>
    @include('partials.theme')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 "Segoe UI", Tahoma, "Noto Sans Arabic", Arial, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        header { background: var(--card); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 5; }
        header .in { max-width: 1100px; margin: 0 auto; padding: 8px 16px; min-height: 56px; display: flex; align-items: center; gap: 12px; }
        header .launcher { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; color: var(--muted); flex: none; }
        header .launcher:hover { background: var(--row-hover); color: var(--text); }
        header .launcher svg { width: 20px; height: 20px; }
        header .app-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 16px; color: var(--text); white-space: nowrap; }
        header .app-title svg { width: 26px; height: 26px; display: block; }
        header nav { display: flex; gap: 2px; flex: 1; overflow-x: auto; }
        header nav a { color: var(--muted); padding: 6px 10px; border-radius: 8px; white-space: nowrap; }
        header nav a:hover { background: var(--row-hover); color: var(--text); }
        header nav a.on { color: var(--text); background: var(--accent-soft); }
        header .user { display: flex; align-items: center; gap: 8px; color: var(--muted); white-space: nowrap; }
        header .user.on, header .user:hover { color: var(--text); }
        header .avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: var(--on-accent); display: grid; place-items: center; font-weight: 700; font-size: 14px; flex: none; }
        header .theme-toggle { white-space: nowrap; }
        main { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
        /* overflow-x : sur telephone, un tableau large defile dans sa carte au lieu d'elargir toute la page */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: right; font-weight: 600; color: var(--muted); font-size: 13px; padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: var(--row-hover); }
        input[type=text], input[type=search], input[type=email], input[type=password] {
            font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; }
        input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
        button, .btn { font: inherit; cursor: pointer; border: 1px solid var(--border); background: var(--card); color: var(--text); border-radius: 8px; padding: 7px 14px; }
        button:hover, .btn:hover { background: var(--row-hover); }
        button.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        button.danger { color: var(--bad); }
        button.link { border: 0; background: none; color: var(--muted); padding: 4px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
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
        @media (max-width: 700px) {
            .hide-sm { display: none; }
            td, th { padding: 7px 6px; }
            header .in { flex-wrap: wrap; gap: 6px 10px; }
            header .app-title { flex: 1; }
            header nav { order: 5; flex-basis: 100%; }
        }
        @media (max-width: 480px) { header .theme-label { display: none; } header .in { gap: 6px 8px; } }
    </style>
    @stack('head')
</head>
<body>
@php
    // application courante (config/apps.php) : son icone, son nom et ses menus dans la barre du haut, facon Odoo
    $user = auth()->user();
    $current = match (true) {
        request()->routeIs('stock.*', 'merge.*', 'branches.*') => 'stock',
        request()->routeIs('users.*') => 'settings',
        default => null,
    };
    $currentApp = $current ? config("apps.{$current}") : null;
@endphp
<header>
    <div class="in">
        <a class="launcher" href="{{ route('home') }}" title="التطبيقات" aria-label="التطبيقات">@include('partials.app-icon', ['name' => 'grid'])</a>
        @if ($currentApp)
            <a class="app-title" href="{{ route($currentApp['route']) }}">@include('partials.app-icon', ['name' => $current]){{ $currentApp['name'] }}</a>
        @else
            <a class="app-title" href="{{ route('home') }}">@include('partials.app-icon', ['name' => 'logo'])مخزون الفروع</a>
        @endif
        <nav>
            @if ($current === 'stock')
                <a href="{{ route('stock.index') }}" class="{{ request()->routeIs('stock.*') ? 'on' : '' }}">البحث</a>
                @if ($user->is_admin)
                    <a href="{{ route('merge.index') }}" class="{{ request()->routeIs('merge.*') ? 'on' : '' }}">دمج الأصناف</a>
                    <a href="{{ route('branches.index') }}" class="{{ request()->routeIs('branches.*') ? 'on' : '' }}">الفروع</a>
                @endif
            @elseif ($current === 'settings')
                <a href="{{ route('users.index') }}" class="on">المستخدمون</a>
            @endif
        </nav>
        @include('partials.theme-toggle')
        <a href="{{ route('account.edit') }}" class="user {{ request()->routeIs('account.*') ? 'on' : '' }}" title="حسابي">
            <span class="avatar">{{ mb_substr($user->name, 0, 1) }}</span><span class="hide-sm">{{ $user->name }}</span>
        </a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link" type="submit">خروج</button></form>
    </div>
</header>
<main>
    @if (session('weak_password'))
        <div class="errors">كلمة السر الحالية تساوي اسم المستخدم. غيّرها من <a href="{{ route('account.edit') }}">حسابي</a>.</div>
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
    window.fetchRetry = async (url, options = {}, tries = 3, timeoutMs = 8000) => {
        for (let attempt = 1; ; attempt++) {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), timeoutMs);
            try {
                const res = await fetch(url, {...options, signal: ctrl.signal});
                if (res.status >= 502 && res.status <= 504 && attempt < tries) throw new Error('HTTP ' + res.status);
                return res;
            } catch (e) {
                if (attempt >= tries) throw (e.name === 'AbortError' ? new Error('لا رد من السيرفر') : e);
                await new Promise(r => setTimeout(r, 500 * attempt));
            } finally {
                clearTimeout(timer);
            }
        }
    };
    // identifiant choisi par la page : une requete POST rejouee est reconnue par le serveur et n'agit qu'une fois
    window.newId = () => (crypto.randomUUID ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)));
</script>
@stack('scripts')
@include('partials.sw')
</body>
</html>
