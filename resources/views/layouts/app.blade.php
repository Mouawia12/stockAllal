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
        header { background: var(--card); border-bottom: 1px solid var(--border); }
        header .in { max-width: 1100px; margin: 0 auto; padding: 10px 16px; display: flex; align-items: center; gap: 18px; }
        header .brand { font-weight: 600; }
        header nav { display: flex; gap: 14px; flex: 1; }
        header nav a { color: var(--muted); padding: 4px 2px; }
        header nav a.on { color: var(--text); border-bottom: 2px solid var(--accent); }
        main { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
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
        @media (max-width: 700px) { .hide-sm { display: none; } td, th { padding: 7px 6px; } }
    </style>
    @stack('head')
</head>
<body>
<header>
    <div class="in">
        <span class="brand">مخزون الفروع</span>
        <nav>
            <a href="{{ route('stock.index') }}" class="{{ request()->routeIs('stock.*') ? 'on' : '' }}">البحث</a>
            @if (auth()->user()->is_admin)
                <a href="{{ route('merge.index') }}" class="{{ request()->routeIs('merge.*') ? 'on' : '' }}">دمج الأصناف</a>
                <a href="{{ route('branches.index') }}" class="{{ request()->routeIs('branches.*') ? 'on' : '' }}">الفروع</a>
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'on' : '' }}">المستخدمون</a>
            @endif
        </nav>
        @include('partials.theme-toggle')
        <a href="{{ route('account.edit') }}" class="small {{ request()->routeIs('account.*') ? '' : 'muted' }}">{{ auth()->user()->name }}</a>
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
@stack('scripts')
</body>
</html>
