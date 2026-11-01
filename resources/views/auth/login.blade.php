<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — مخزون الفروع</title>
    <style>
        :root { --bg: #f5f6f8; --card: #fff; --text: #1d2228; --muted: #67707a; --border: #e1e4e8; --accent: #1f6feb; --on-accent: #fff; --bad: #cf222e; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1216; --card: #171b21; --text: #e6edf3; --muted: #8b949e; --border: #2d333b; --accent: #4493f8; --on-accent: #0f1216; --bad: #f85149; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 15px/1.6 "Segoe UI", Tahoma, "Noto Sans Arabic", Arial, sans-serif; padding: 16px; }
        form { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; width: 100%; max-width: 360px; }
        h1 { font-size: 20px; margin: 0 0 18px; }
        label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
        input[type=email], input[type=password] { width: 100%; font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 9px 10px; }
        .remember { display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 14px; color: var(--muted); }
        button { width: 100%; margin-top: 18px; font: inherit; cursor: pointer; border: 0; border-radius: 8px; padding: 10px; background: var(--accent); color: var(--on-accent); }
        .error { color: var(--bad); font-size: 14px; margin-top: 10px; }
    </style>
</head>
<body>
<form method="post" action="{{ url('login') }}">
    @csrf
    <h1>مخزون الفروع</h1>
    <label for="email">البريد الإلكتروني</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" dir="ltr">
    <label for="password">كلمة السر</label>
    <input id="password" type="password" name="password" required autocomplete="current-password" dir="ltr">
    <label class="remember"><input type="checkbox" name="remember" value="1"> تذكّرني</label>
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif
    <button type="submit">دخول</button>
</form>
</body>
</html>
