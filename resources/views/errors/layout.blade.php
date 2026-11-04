{{-- Gabarit des pages d'erreur : autonome (pas de session ni de base), pour s'afficher meme quand l'application va mal. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('app.locales')[app()->getLocale()]['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ __('common.app_name') }}</title>
    @include('partials.favicon')
    @include('partials.fonts')
    @include('partials.theme')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; color: var(--text); font: 15px/1.6 var(--font);
            background: var(--bg) radial-gradient(700px 340px at 100% 0%, var(--accent-soft), transparent 70%); }
        .box { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px 28px; max-width: 440px; width: 100%; text-align: center; box-shadow: var(--shadow); }
        .box svg { width: 52px; height: 52px; }
        h1 { font-size: 21px; margin: 14px 0 8px; }
        p { color: var(--muted); margin: 0 0 22px; }
        .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-block; border-radius: 10px; padding: 9px 20px; text-decoration: none; font: inherit; cursor: pointer; border: 1px solid var(--border); background: var(--card); color: var(--text); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        .muted { color: var(--muted); font-size: 13px; margin: 16px 0 0; }
    </style>
</head>
<body>
<div class="box">
    @include('partials.app-icon', ['name' => 'logo'])
    <h1>@yield('heading')</h1>
    <p>@yield('message')</p>
    <div class="actions">@yield('actions')</div>
    @yield('after')
</div>
</body>
</html>
