<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une connexion dure une journee de travail : config('session.lifetime') minutes (8 h) apres la connexion, meme si
 * le compte s'en sert encore, puis retour a la page de connexion. La duree de Laravel seule compte depuis le dernier
 * clic : le PC d'un magasin laisse allume resterait connecte jusqu'au milieu de la nuit.
 * "تذكّرني" coche = le choix de rester connecte : LoginController ne pose pas la cle, pas de limite.
 */
class ExpireLogin
{
    public const KEY = 'login_started_at';

    public function handle(Request $request, Closure $next): Response
    {
        $started = $request->session()->get(self::KEY);
        if ($started === null || now()->getTimestamp() - $started < config('session.lifetime') * 60) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = __('login.expired', ['hours' => round(config('session.lifetime') / 60, 1)]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }
        // guest() : apres la reconnexion, retour a la meme page (pas au fragment de la recherche pendant la frappe)
        $redirect = $request->boolean('partial') ? redirect()->route('login') : redirect()->guest(route('login'));

        return $redirect->withErrors(['session' => $message]);
    }
}
