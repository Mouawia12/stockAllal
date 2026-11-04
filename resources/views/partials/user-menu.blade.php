{{--
    Menu du compte, facon Odoo : l'avatar de la barre du haut ouvre le nom, "حسابي", l'apparence, la langue et "خروج".
    La barre garde ainsi l'application, ses menus, la branche, la recherche et l'avatar. Ouverture / fermeture / fleches :
    details.drop dans layouts/app. La langue reste un POST (partials/lang-switch), l'apparence reste dans ce navigateur.
--}}
@php
    $me = auth()->user();
    $meRole = in_array($me->role, App\Support\Roles::ALL, true) ? __("users.roles.{$me->role}.title") : null;
    $themes = ['auto' => __('common.theme.auto'), 'light' => __('common.theme.light'), 'dark' => __('common.theme.dark')];
@endphp
<details class="drop user-menu">
    <summary title="{{ $me->name }}" aria-label="{{ __('common.account') }}"><span class="avatar">{{ mb_substr($me->name, 0, 1) }}</span></summary>
    <div class="drop-menu">
        <div class="um-who">
            <span class="avatar">{{ mb_substr($me->name, 0, 1) }}</span>
            <span class="um-id">
                <strong><bdi>{{ $me->name }}</bdi></strong>
                <span class="muted small"><span class="ltr">{{ $me->username }}</span>@if ($meRole) · {{ $meRole }}@endif @if ($me->branch) · <bdi>{{ $me->branch->name }}</bdi>@endif</span>
            </span>
        </div>
        <a href="{{ route('account.edit') }}" @class(['um-item', 'on' => request()->routeIs('account.*')])>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
            {{ __('common.account') }}
        </a>
        <div class="um-row">
            <span>{{ __('common.theme.label') }}</span>
            <div class="seg" id="theme-seg" role="group" aria-label="{{ __('common.theme.label') }}">
                @foreach ($themes as $t => $label)
                    <button type="button" data-theme-set="{{ $t }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="um-row">
            <span>{{ __('common.language') }}</span>
            @include('partials.lang-switch')
        </div>
        <hr>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="um-item">
                <svg class="flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/></svg>
                {{ __('common.logout') }}
            </button>
        </form>
    </div>
</details>
<style>
    .user-menu summary { padding: 3px; border-radius: 50%; }
    .user-menu summary:hover .avatar, .user-menu[open] summary .avatar { box-shadow: 0 0 0 3px var(--accent-soft); }
    .user-menu .drop-menu { width: 280px; }
    .um-who { display: flex; align-items: center; gap: 10px; padding: 8px 10px 10px; border-bottom: 1px solid var(--border); margin-bottom: 4px; }
    .um-who .avatar { width: 38px; height: 38px; font-size: 17px; }
    .um-id { display: flex; flex-direction: column; min-width: 0; line-height: 1.4; }
    .um-id > * { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .um-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; border-radius: 8px; background: none; color: var(--text); text-align: start; }
    .um-item svg { width: 18px; height: 18px; color: var(--muted); flex: none; }
    .um-item:hover, .um-item.on { background: var(--row-hover); }
    /* rtl : la fleche de sortie regarde vers le debut de la ligne */
    [dir="rtl"] .um-item svg.flip { transform: scaleX(-1); }
    .um-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 10px; color: var(--muted); font-size: 14px; }
    .user-menu .seg, .user-menu .lang-switch { display: flex; gap: 2px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; }
    .user-menu .seg button, .user-menu .lang-switch button { border: 0; background: none; color: var(--muted); font-size: 13px; line-height: 1.4; padding: 3px 9px; border-radius: 6px; }
    .user-menu .seg button:hover, .user-menu .lang-switch button:hover { background: var(--row-hover); color: var(--text); }
    .user-menu .seg button[aria-pressed="true"], .user-menu .lang-switch button.on { background: var(--accent-soft); color: var(--text); font-weight: 600; }
    .user-menu hr { border: 0; border-top: 1px solid var(--border); margin: 4px 0; }
    .user-menu form { margin: 0; }
</style>
<script>
    // apparence : تلقائي (absent = reglage de Windows / du telephone), نهاري, ليلي ; meme cle que partials/theme et partials/theme-toggle
    (() => {
        const seg = document.getElementById('theme-seg');
        let theme = 'auto';
        try { theme = localStorage.getItem('theme') || 'auto'; } catch (e) {}
        const mark = () => seg.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.themeSet === theme)));
        seg.addEventListener('click', e => {
            const t = e.target.closest('button')?.dataset.themeSet;
            if (!t) return;
            theme = t;
            try { t === 'auto' ? localStorage.removeItem('theme') : localStorage.setItem('theme', t); } catch (e) {}
            if (t === 'auto') document.documentElement.removeAttribute('data-theme');
            else document.documentElement.setAttribute('data-theme', t);
            mark();
        });
        mark();
    })();
</script>
