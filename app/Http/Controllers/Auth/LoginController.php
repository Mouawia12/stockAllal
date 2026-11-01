<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => 'اسم المستخدم مطلوب',
            'password.required' => 'كلمة السر مطلوبة',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['username' => 'اسم المستخدم أو كلمة السر غير صحيحة'])->onlyInput('username');
        }

        $request->session()->regenerate();
        // rappel affiche tant que le mot de passe initial (= nom d'utilisateur) n'a pas ete change
        $request->session()->put('weak_password', $credentials['password'] === $credentials['username']);

        return redirect()->intended(route('stock.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
