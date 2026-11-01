{{-- Champs communs a la creation (index) et a la modification (edit) d'un compte. $user = null pour un nouveau compte. --}}
<div class="user-form">
    <label>
        <span>الاسم الظاهر</span>
        <input type="text" name="name" value="{{ old('name', $user?->name) }}" maxlength="100" required>
    </label>
    <label>
        <span>اسم المستخدم (للدخول)</span>
        <input type="text" name="username" value="{{ old('username', $user?->username) }}" maxlength="50" required dir="ltr" autocomplete="off">
    </label>
    <label>
        <span>{{ $user ? 'كلمة سر جديدة (اتركها فارغة للإبقاء على الحالية)' : 'كلمة السر (8 أحرف على الأقل)' }}</span>
        <span class="pw">
            <input type="password" name="password" minlength="8" {{ $user ? '' : 'required' }} dir="ltr" autocomplete="new-password">
            <button type="button" class="link show-pw" aria-label="إظهار كلمة السر">إظهار</button>
        </span>
    </label>
    <label class="check">
        {{-- apres une erreur, une case decochee n'est pas dans old() : sans ce test elle reviendrait cochee pour un administrateur --}}
        <input type="checkbox" name="is_admin" value="1" @checked($errors->any() ? old('is_admin') : $user?->is_admin)>
        <span><b>مدير</b> — صلاحيات كاملة: الفروع، تنزيل الوكيل، والحسابات. بدونها يرى الحساب المخزون فقط.</span>
    </label>
</div>
@once
    @push('head')
    <style>
        .user-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end; }
        .user-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--muted); }
        .user-form input[type=text], .user-form input[type=password] { width: 100%; }
        .user-form .pw { display: flex; gap: 4px; }
        .user-form .check { grid-column: 1 / -1; flex-direction: row; align-items: center; gap: 8px; color: var(--text); font-size: 14px; }
        .user-form .check input { width: 18px; height: 18px; }
    </style>
    @endpush
    @push('scripts')
    <script>
        document.querySelectorAll('.show-pw').forEach(b => b.addEventListener('click', () => {
            const input = b.parentElement.querySelector('input');
            input.type = input.type === 'password' ? 'text' : 'password';
            b.textContent = input.type === 'password' ? 'إظهار' : 'إخفاء';
        }));
    </script>
    @endpush
@endonce
