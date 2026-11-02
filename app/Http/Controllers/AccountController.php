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
            'name.required' => __('users.name_required'),
            'username.required' => __('users.username_required'),
            'username.unique' => __('users.username_taken'),
            'current_password.required' => __('users.current_password_required'),
            'current_password.current_password' => __('users.current_password_wrong'),
            'password.min' => __('users.new_password_min'),
            'password.confirmed' => __('users.password_mismatch'),
        ]);

        $passwordChanged = ! empty($data['password']);
        $user->name = $data['name'];
        $user->username = $data['username'];
        if ($passwordChanged) {
            $user->password = $data['password'];
        }
        $user->save();

        if ($passwordChanged) {
            $this->closeOtherSessions($request, $user);
        }

        $password = $passwordChanged ? $data['password'] : $data['current_password'];
        $request->session()->put('weak_password', $password === $user->username);

        return back()->with('status', __('users.account_saved'));
    }
}
