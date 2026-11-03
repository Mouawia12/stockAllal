{{--
    Formulaire d'un compte (creation et modification) : identite, role et branche, permissions.
    Seulement ce que l'auteur peut donner (UserController::formData) : $roles, $branches (celles qu'il gere),
    $grantable (ses propres permissions ; les autres restent affichees, grisees, telles qu'elles sont sur le compte).
    $user = null pour un nouveau compte : les permissions par defaut sont cochees (App\Support\Permissions::DEFAULTS).
--}}
@use('App\Support\Permissions')
@use('App\Support\Roles')
@php
    // apres une erreur de validation, l'etat saisi ; sinon celui du compte (une case decochee n'est pas dans old())
    $old = $errors->any();
    $role = $old ? old('role') : ($user?->role ?? (in_array(Roles::USER, $roles, true) ? Roles::USER : $roles[0]));
    $checked = $old ? (array) old('permissions', []) : ($user ? ($user->permissions ?? []) : Permissions::DEFAULTS);
    $branchId = (int) ($old ? old('branch_id') : ($user?->branch_id ?? ($branches->count() === 1 ? $branches->first()->id : 0)));
    $ownerIds = $old ? array_map('intval', (array) old('branches', [])) : $ownerBranches;
    $active = $old ? (bool) old('active') : ($user?->active ?? true);
    $perms = __('users.perms');
    $roleTexts = __('users.roles');
    $icons = [
        'developer' => '<path d="M8 9l-4 3 4 3M16 9l4 3-4 3M13.5 6l-3 12"/>',
        'owner' => '<path d="M3 21h18M5 21V8l7-4 7 4v13"/><path d="M9 21v-5h6v5M9 11h.01M15 11h.01"/>',
        'branch_manager' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
    ];
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
        <label class="active-box">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked($active)>
            <span><b>{{ __('users.active_label') }}</b> <span class="muted small">{{ __('users.active_help') }}</span></span>
        </label>
    </section>

    <section class="card uf-card">
        <h2>{{ __('users.section_role') }}</h2>
        <div class="roles">
            @foreach ($roles as $r)
                <label class="role">
                    <input type="radio" name="role" value="{{ $r }}" @checked($role === $r)>
                    <span class="role-body">
                        <span class="role-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$r] !!}</svg>
                            {{ $roleTexts[$r]['title'] }}
                        </span>
                        <span class="muted small">{{ $roleTexts[$r]['help'] }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        {{-- directeur de branche, employe : leur branche --}}
        <label class="branch-pick">
            <span>{{ __('users.branch_label') }}</span>
            <select name="branch_id">
                @if ($branches->count() !== 1)
                    <option value="">{{ __('users.branch_choose') }}</option>
                @endif
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </label>

        {{-- directeur de la societe : ses branches (seul le developpeur donne ce role) --}}
        @if (in_array(Roles::OWNER, $roles, true))
            <div class="owner-pick">
                <span>{{ __('users.owner_branches_label') }}</span>
                <span class="muted small">{{ __('users.owner_branches_help') }}</span>
                <div class="branch-grid">
                    @foreach ($branches as $b)
                        <label class="perm">
                            <input type="checkbox" name="branches[]" value="{{ $b->id }}" @checked(in_array($b->id, $ownerIds, true))>
                            <span><b>{{ $b->name }}</b></span>
                        </label>
                    @endforeach
                </div>
            </div>
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
        <p class="admin-note pill ok">{{ __('users.perms_developer_note') }}</p>
        @if (count($grantable) < count(Permissions::all()))
            <p class="muted small">{{ __('users.perms_grey_note') }}</p>
        @endif
        <div class="perm-area">
            @foreach (Permissions::GROUPS as $group => $list)
                <div class="perm-group">
                    <h3>{{ __("users.perm_groups.{$group}") }}</h3>
                    <div class="perm-grid">
                        @foreach ($list as $p)
                            @php($can = in_array($p, $grantable, true))
                            <label class="perm" @unless ($can) title="{{ __('users.perm_not_grantable') }}" @endunless>
                                <input type="checkbox" name="permissions[]" value="{{ $p }}" @checked(in_array($p, $checked, true)) @disabled(! $can)>
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
        .active-box { display: flex; gap: 10px; align-items: center; margin-top: 14px; cursor: pointer; }
        .active-box input { width: 18px; height: 18px; }
        .branch-pick, .owner-pick { display: none; flex-direction: column; gap: 6px; margin-top: 14px; font-size: 13px; color: var(--muted); }
        .branch-pick select { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; max-width: 320px; }
        .branch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; color: var(--text); }
        .uf:has(input[name=role][value=branch_manager]:checked) .branch-pick,
        .uf:has(input[name=role][value=user]:checked) .branch-pick,
        .uf:has(input[name=role][value=owner]:checked) .owner-pick { display: flex; }
        .perm:has(input:disabled) { opacity: .55; cursor: default; }
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
        /* developpeur : toutes les permissions, les cases ne comptent pas */
        .uf:has(input[name=role][value=developer]:checked) .admin-note { display: inline-block; }
        .uf:has(input[name=role][value=developer]:checked) .perm-area,
        .uf:has(input[name=role][value=developer]:checked) .presets { opacity: .4; pointer-events: none; }
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

        // les cases grisees (permissions que l'auteur n'a pas) ne bougent jamais
        const boxes = [...document.querySelectorAll('input[name="permissions[]"]')].filter(b => !b.disabled);
        const box = p => boxes.find(b => b.value === p);
        const DEFAULTS = @json(Permissions::DEFAULTS);
        const NEED_VIEW = @json(Permissions::NEED_BRANCHES_VIEW);

        // memes dependances que Permissions::normalize() : sans "رؤية الفروع", pas de page des succursales
        boxes.forEach(b => b.addEventListener('change', () => {
            if (b.checked && NEED_VIEW.includes(b.value) && box('branches.view')) box('branches.view').checked = true;
            if (!b.checked && b.value === 'branches.view') NEED_VIEW.forEach(p => { if (box(p)) box(p).checked = false; });
        }));

        document.querySelectorAll('[data-preset]').forEach(btn => btn.addEventListener('click', () => {
            const preset = btn.dataset.preset;
            boxes.forEach(b => { b.checked = preset === 'all' || (preset === 'default' && DEFAULTS.includes(b.value)); });
        }));
    })();
    </script>
    @endpush
@endonce
