<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue de l'interface : cookie "locale" (boutons عربي / FR / EN, LocaleController), sinon config('app.locale') = ar.
 * Middleware global, avant la maintenance : les pages 503 / 500 / 403 sont traduites aussi.
 * Le cookie n'est pas chiffre (bootstrap/app.php) : il est lu ici avant EncryptCookies, et par public/offline.html.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->cookie('locale');
        if (array_key_exists($locale, config('app.locales'))) {
            // met aussi Carbon a jour (diffForHumans : "منذ 3 دقائق", "il y a 3 minutes")
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
