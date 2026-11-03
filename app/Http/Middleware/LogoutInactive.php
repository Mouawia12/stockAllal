<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte desactive sur la page des comptes (users.active) : il sort a son prochain clic, sans attendre la fin
 * de sa connexion. LoginController refuse deja de le reconnecter.
 */
class LogoutInactive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = __('login.disabled');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->withErrors(['username' => $message]);
    }
}
