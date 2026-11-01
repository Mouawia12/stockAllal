<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>غير مسموح — مخزون الفروع</title>
    @include('partials.theme')
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 15px/1.6 "Segoe UI", Tahoma, "Noto Sans Arabic", Arial, sans-serif; padding: 16px; }
        .box { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; max-width: 420px; text-align: center; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 0 18px; }
        a { display: inline-block; background: var(--accent); color: var(--on-accent); text-decoration: none; border-radius: 8px; padding: 8px 18px; }
    </style>
</head>
<body>
<div class="box">
    <h1>هذه الصفحة للمدير فقط</h1>
    <p>حسابك يسمح بالبحث في المخزون فقط. إذا كنت تحتاج صلاحيات أكثر، اطلبها من المدير.</p>
    <a href="{{ url('/') }}">الرجوع إلى البحث</a>
</div>
</body>
</html>
