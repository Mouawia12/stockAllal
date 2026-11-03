{{--
    Formulaire d'un compte (creation et modification) en trois cartes : identite, type de compte, permissions.
    $user = null pour un nouveau compte : les permissions par defaut sont cochees (App\Support\Permissions::DEFAULTS).
--}}
@use('App\Support\Permissions')
@php
    $isSelf = $user && $user->is(auth()->user());
    // apres une erreur de validation, l'etat saisi ; sinon celui du compte (une case decochee n'est pas dans old())
    $isAdmin = $isSelf || (bool) ($errors->any() ? old('is_admin') : $user?->is_admin);
    $checked = $errors->any() ? (array) old('permissions', []) : ($user ? ($user->permissions ?? []) : Permissions::DEFAULTS);
    $perms = __('users.perms');
@endphp
<div class="uf">
    <section class="card uf-card">
        <h2>{{ __('users.section_account') }}</h2>
        <div class="uf-fields">
            <label>
                <span>{{ __('users.display_name') }}</span>
                <input type="text" name="name" value="{{ old('name', $user?->name) }}" maxlength="100" required>
            </label>
            <label>
                <span>{{ __('users.login_username') }}</span>
                <input type="text" name="username" value="{{ old('username', $user?->username) }}" maxlength="50" required dir="ltr" autocomplete="off">
            </label>
            <label>
                <span>{{ $user ? __('users.password_keep') : __('users.password_new') }}</span>
                <span class="pw">
                    <input type="password" name="password" minlength="8" {{ $user ? '' : 'required' }} dir="ltr" autocomplete="new-password">
                    <button type="button" class="link show-pw" aria-label="{{ __('users.show_password') }}">{{ __('common.show') }}</button>
                </span>
            </label>
        </div>
    </section>

    <section class="card uf-card">
        <h2>{{ __('users.section_role') }}</h2>
        <div class="roles">
            <label class="role">
                <input type="radio" name="is_admin" value="0" @checked(! $isAdmin) @disabled($isSelf)>
                <span class="role-body">
                    <span class="role-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                        {{ __('users.role_user') }}
                    </span>
                    <span class="muted small">{{ __('users.role_user_help') }}</span>
                </span>
            </label>
            <label class="role">
                <input type="radio" name="is_admin" value="1" @checked($isAdmin) @disabled($isSelf)>
                <span class="role-body">
                    <span class="role-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>
                        {{ __('users.role_admin') }}
                    </span>
                    <span class="muted small">{{ __('users.role_admin_help') }}</span>
                </span>
            </label>
        </div>
        @if ($isSelf)
            {{-- un bouton radio desactive n'est pas envoye : son propre compte reste administrateur --}}
            <input type="hidden" name="is_admin" value="1">
            <p class="muted small">{{ __('users.own_account') }}</p>
        @endif
    </section>

    <section class="card uf-card perms-card">
        <div class="perms-head">
            <h2>{{ __('users.section_perms') }}</h2>
            <span class="presets">
                <button type="button" data-preset="default">{{ __('users.perms_preset_default') }}</button>
                <button type="button" data-preset="all">{{ __('users.perms_all') }}</button>
                <button type="button" data-preset="none">{{ __('users.perms_none') }}</button>
            </span>
        </div>
        <p class="muted small">{{ __('users.perms_always') }}</p>
        <p class="admin-note pill ok">{{ __('users.perms_admin_note') }}</p>
        <div class="perm-area">
            @foreach (Permissions::GROUPS as $group => $list)
                <div class="perm-group">
                    <h3>{{ __("users.perm_groups.{$group}") }}</h3>
                    <div class="perm-grid">
                        @foreach ($list as $p)
                            <label class="perm">
                                <input type="checkbox" name="permissions[]" value="{{ $p }}" @checked(in_array($p, $checked, true))>
                                <span>
                                    <b>{{ $perms[$p]['title'] }}</b>
                                    <span class="muted small">{{ $perms[$p]['help'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@once
    @push('head')
    <style>
        .uf { display: grid; gap: 16px; }
        .uf-card h2 { font-size: 16px; margin: 0 0 12px; }
        .uf-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; align-items: end; }
        .uf-fields label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--muted); }
        .uf-fields input[type=text], .uf-fields input[type=password] { width: 100%; }
        .uf .pw { display: flex; gap: 4px; }
        .roles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .role { display: flex; gap: 10px; align-items: flex-start; border: 1.5px solid var(--border); border-radius: 12px; padding: 12px 14px; cursor: pointer; }
        .role:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
        .role:has(input:disabled) { cursor: default; }
        .role input { margin-top: 5px; }
        .role-body { display: flex; flex-direction: column; gap: 2px; }
        .role-title { display: flex; align-items: center; gap: 8px; font-weight: 600; }
        .role-title svg { width: 20px; height: 20px; color: var(--accent); flex: none; }
        .perms-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
        .perms-head h2 { margin: 0; flex: 1; }
        .presets { display: flex; gap: 6px; }
        .presets button { padding: 4px 10px; font-size: 13px; }
        .perm-group h3 { font-size: 13px; font-weight: 600; color: var(--muted); margin: 16px 0 8px; }
        .perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
        .perm { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; cursor: pointer; }
        .perm:hover { background: var(--row-hover); }
        .perm:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
        .perm input { width: 18px; height: 18px; margin-top: 2px; flex: none; }
        .perm b { display: block; font-size: 14px; }
        .admin-note { display: none; margin: 4px 0 0; }
        /* administrateur : toutes les permissions, les cases ne comptent pas */
        .uf:has(input[name=is_admin][value="1"]:checked) .admin-note { display: inline-block; }
        .uf:has(input[name=is_admin][value="1"]:checked) .perm-area,
        .uf:has(input[name=is_admin][value="1"]:checked) .presets { opacity: .4; pointer-events: none; }
    </style>
    @endpush
    @push('scripts')
    <script>
    (() => {
        document.querySelectorAll('.show-pw').forEach(b => b.addEventListener('click', () => {
            const input = b.parentElement.querySelector('input');
            input.type = input.type === 'password' ? 'text' : 'password';
            b.textContent = input.type === 'password' ? @json(__('common.show')) : @json(__('common.hide'));
        }));

        const boxes = [...document.querySelectorAll('input[name="permissions[]"]')];
        const box = p => boxes.find(b => b.value === p);
        const DEFAULTS = @json(Permissions::DEFAULTS);
        const NEED_VIEW = @json(Permissions::NEED_BRANCHES_VIEW);

        // memes dependances que Permissions::normalize() : sans "رؤية الفروع", pas de page des succursales
        boxes.forEach(b => b.addEventListener('change', () => {
            if (b.checked && NEED_VIEW.includes(b.value)) box('branches.view').checked = true;
            if (!b.checked && b.value === 'branches.view') NEED_VIEW.forEach(p => { box(p).checked = false; });
        }));

        document.querySelectorAll('[data-preset]').forEach(btn => btn.addEventListener('click', () => {
            const preset = btn.dataset.preset;
            boxes.forEach(b => { b.checked = preset === 'all' || (preset === 'default' && DEFAULTS.includes(b.value)); });
        }));
    })();
    </script>
    @endpush
@endonce
