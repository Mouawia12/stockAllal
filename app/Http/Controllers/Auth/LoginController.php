<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExpireLogin;
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
            'username.required' => __('login.username_required'),
            'password.required' => __('login.password_required'),
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['username' => __('login.failed')])->onlyInput('username');
        }
        // compte desactive : le bon mot de passe ne suffit pas, et on dit pourquoi
        if (! $request->user()->active) {
            Auth::logout();

            return back()->withErrors(['username' => __('login.disabled')])->onlyInput('username');
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();
        // fin de la connexion 8 h plus tard (ExpireLogin), sauf "تذكّرني"
        if (! $request->boolean('remember')) {
            $request->session()->put(ExpireLogin::KEY, now()->getTimestamp());
        }
        // rappel affiche tant que le mot de passe initial (= nom d'utilisateur) n'a pas ete change
        $request->session()->put('weak_password', $credentials['password'] === $credentials['username']);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
