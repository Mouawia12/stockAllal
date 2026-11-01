<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — مخزون الفروع</title>
    @include('partials.theme')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--card); color: var(--text); font: 15px/1.6 "Segoe UI", Tahoma, "Noto Sans Arabic", Arial, sans-serif; }
        .shell { min-height: 100vh; display: grid; grid-template-columns: minmax(400px, 1fr) 1.15fr; }

        /* formulaire */
        .form-side { position: relative; display: grid; place-items: center; padding: 48px 24px; }
        .form-wrap { width: 100%; max-width: 380px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 36px; }
        .brand svg { width: 46px; height: 46px; flex: none; }
        .brand b { display: block; font-size: 18px; }
        .brand span { display: block; font-size: 13px; color: var(--muted); }
        h1 { font-size: 26px; margin: 0 0 6px; }
        .sub { margin: 0 0 26px; color: var(--muted); }
        label.f { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        .field { position: relative; }
        .field > svg { position: absolute; top: 50%; right: 13px; width: 18px; height: 18px; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
        .field input { width: 100%; height: 46px; font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 10px;
            padding: 0 42px 0 12px; transition: border-color .15s, box-shadow .15s; }
        .field input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .field input#password { padding-left: 74px; }
        .eye { position: absolute; top: 50%; left: 6px; transform: translateY(-50%); font: inherit; font-size: 13px; border: 0; background: none; color: var(--accent); cursor: pointer; padding: 6px 8px; border-radius: 6px; }
        .eye:hover { background: var(--accent-soft); }
        .caps { margin: 6px 0 0; font-size: 13px; color: var(--warn); }
        .remember { display: flex; gap: 8px; align-items: center; margin-top: 16px; font-size: 14px; color: var(--muted); cursor: pointer; }
        .remember input { width: 17px; height: 17px; accent-color: var(--accent); margin: 0; }
        .submit { width: 100%; height: 48px; margin-top: 24px; font: inherit; font-weight: 600; font-size: 16px; cursor: pointer; border: 0; border-radius: 10px;
            background: var(--accent); color: var(--on-accent); display: flex; align-items: center; justify-content: center; gap: 10px; transition: filter .15s, transform .05s; }
        .submit:hover { filter: brightness(1.08); }
        .submit:active { transform: translateY(1px); }
        .submit:disabled { cursor: wait; filter: saturate(.7); }
        .submit .spin { width: 16px; height: 16px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; display: none; }
        .submit:disabled .spin { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .alert { display: flex; gap: 10px; align-items: flex-start; padding: 11px 13px; border-radius: 10px; background: var(--bad-bg); color: var(--bad); font-size: 14px; margin-bottom: 4px; }
        .alert svg { width: 18px; height: 18px; flex: none; margin-top: 2px; }
        .foot { margin: 40px 0 0; font-size: 12px; color: var(--muted); text-align: center; }
        .theme-toggle { position: absolute; top: 16px; right: 16px; font: inherit; font-size: 13px; cursor: pointer; border: 1px solid var(--border); border-radius: 99px; padding: 5px 12px; background: var(--card); color: var(--muted); }

        /* panneau de presentation */
        .showcase { position: relative; overflow: hidden; color: #fff; display: grid; place-items: center; padding: 56px;
            background: linear-gradient(150deg, #7a4f70 0%, #5a4072 48%, #2f3a66 100%); }
        .showcase::before, .showcase::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .07); }
        .showcase::before { width: 520px; height: 520px; top: -180px; left: -160px; }
        .showcase::after { width: 380px; height: 380px; bottom: -150px; right: -90px; background: rgba(255, 255, 255, .05); }
        .show-in { position: relative; max-width: 470px; }
        .show-in h2 { font-size: 32px; line-height: 1.35; margin: 0 0 12px; }
        .show-in > p { margin: 0 0 28px; color: rgba(255, 255, 255, .78); font-size: 16px; }
        .features { list-style: none; padding: 0; margin: 0 0 36px; display: grid; gap: 12px; }
        .features li { display: flex; gap: 10px; align-items: center; color: rgba(255, 255, 255, .92); }
        .features li::before { content: "✓"; display: grid; place-items: center; width: 24px; height: 24px; flex: none; border-radius: 50%; background: rgba(255, 255, 255, .16); font-size: 13px; font-weight: 700; }
        .mini-apps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
        .mini { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 4px 10px; border-radius: 14px;
            background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .16); }
        .mini .ic { width: 44px; height: 44px; border-radius: 12px; background: #fff; display: grid; place-items: center; }
        .mini svg { width: 30px; height: 30px; }
        .mini b { font-size: 12px; font-weight: 600; }
        .mini small { font-size: 10px; color: rgba(255, 255, 255, .7); line-height: 1; }

        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; }
            .showcase { display: none; }
            body { background: var(--bg); }
            .form-side { background: radial-gradient(600px 300px at 100% 0%, var(--accent-soft), transparent 70%); }
            .form-wrap { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 28px 22px; box-shadow: var(--shadow); }
            .brand { margin-bottom: 26px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="form-side">
        @include('partials.theme-toggle')
        <div class="form-wrap">
            <div class="brand">
                @include('partials.app-icon', ['name' => 'logo'])
                <div><b>مخزون الفروع</b><span>منصة إدارة الفروع</span></div>
            </div>

            <h1>تسجيل الدخول</h1>
            <p class="sub">أدخل اسم المستخدم وكلمة السر للمتابعة.</p>

            @if ($errors->any())
                <div class="alert" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5.5M12 16.5v.01"/></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="post" action="{{ url('login') }}" id="login-form">
                @csrf
                <label class="f" for="username">اسم المستخدم</label>
                <div class="field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20.5c0-4.2 3.6-7 8-7s8 2.8 8 7"/></svg>
                    <input id="username" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" dir="ltr">
                </div>

                <label class="f" for="password">كلمة السر</label>
                <div class="field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
                    <input id="password" type="password" name="password" required autocomplete="current-password" dir="ltr">
                    <button type="button" class="eye" id="eye" aria-pressed="false">إظهار</button>
                </div>
                <p class="caps" id="caps" hidden>⇪ زر الأحرف الكبيرة (Caps Lock) مفعّل</p>

                <label class="remember"><input type="checkbox" name="remember" value="1"> تذكّرني على هذا الجهاز</label>

                <button class="submit" type="submit" id="submit"><span class="spin"></span><span>دخول</span></button>
            </form>

            <p class="foot">© {{ date('Y') }} مخزون الفروع</p>
        </div>
    </section>

    <aside class="showcase" aria-hidden="true">
        <div class="show-in">
            <h2>كل فروعك في شاشة واحدة</h2>
            <p>مخزون كل فرع وأسعاره لحظة بلحظة، من أي مكان.</p>
            <ul class="features">
                <li>كمية كل منتج في كل الفروع، مع تحديث فوري من الفرع</li>
                <li>دمج الأصناف المكتوبة بأكواد مختلفة بين الفروع</li>
                <li>سعر الفرع الرئيسي، وتنبيه للفروع التي لم تحدّث سعرها</li>
            </ul>
            <div class="mini-apps">
                @foreach (collect(config('apps'))->reject(fn ($app) => ! empty($app['admin'])) as $key => $app)
                    <div class="mini">
                        <span class="ic">@include('partials.app-icon', ['name' => $key])</span>
                        <b>{{ $app['name'] }}</b>
                        <small>{{ $app['route'] ? 'متاح' : 'قريباً' }}</small>
                    </div>
                @endforeach
            </div>
        </div>
    </aside>
</div>

<script>
(() => {
    const pw = document.getElementById('password');
    const eye = document.getElementById('eye');
    const caps = document.getElementById('caps');
    eye.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.textContent = show ? 'إخفاء' : 'إظهار';
        eye.setAttribute('aria-pressed', String(show));
        pw.focus();
    });
    const checkCaps = e => { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); };
    pw.addEventListener('keydown', checkCaps);
    pw.addEventListener('keyup', checkCaps);
    pw.addEventListener('blur', () => { caps.hidden = true; });
    const btn = document.getElementById('submit');
    document.getElementById('login-form').addEventListener('submit', () => {
        btn.disabled = true;
        btn.lastElementChild.textContent = 'جارٍ الدخول…';
    });
    // retour arriere du navigateur (page en cache) : le bouton ne doit pas rester bloque
    window.addEventListener('pageshow', () => {
        btn.disabled = false;
        btn.lastElementChild.textContent = 'دخول';
    });
})();
</script>
@include('partials.sw')
</body>
</html>
