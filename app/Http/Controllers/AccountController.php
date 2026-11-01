<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Page "حسابي" : changer le nom affiche, le nom d'utilisateur et le mot de passe. */
class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user->id)],
            'current_password' => ['required', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'الاسم مطلوب',
            'username.required' => 'اسم المستخدم مطلوب',
            'username.unique' => 'اسم المستخدم مستعمل من حساب آخر',
            'current_password.required' => 'أدخل كلمة السر الحالية لتأكيد التغيير',
            'current_password.current_password' => 'كلمة السر الحالية غير صحيحة',
            'password.min' => 'كلمة السر الجديدة: 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة السر الجديدة غير مطابق',
        ]);

        $user->name = $data['name'];
        $user->username = $data['username'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        $password = ! empty($data['password']) ? $data['password'] : $data['current_password'];
        $request->session()->put('weak_password', $password === $user->username);

        return back()->with('status', 'تم حفظ الحساب');
    }
}
