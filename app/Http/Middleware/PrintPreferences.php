<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages d'impression : leur langue (le francais par defaut, celle des documents de Gescomm, quelle que soit la langue de
 * l'interface), le format d'un bon (A4 / A5) et sa colonne de codes. Un choix dans la barre (?lang= ?size= ?code=) reste
 * dans ce navigateur (cookie "print") : chaque poste garde ses reglages sans qu'on les refasse a chaque bon.
 */
class PrintPreferences
{
    public const DEFAULTS = ['lang' => 'fr', 'size' => 'a4', 'code' => '1'];

    private const SIZES = ['a4', 'a5'];

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie('print');
        $saved = is_string($cookie) ? json_decode($cookie, true) : null;
        $prefs = self::DEFAULTS;
        $changed = false;
        foreach (array_keys(self::DEFAULTS) as $key) {
            $query = $request->query($key);
            if (is_string($query) && $this->valid($key, $query)) {
                $prefs[$key] = $query;
                $changed = true;
            } elseif (is_array($saved) && is_string($saved[$key] ?? null) && $this->valid($key, $saved[$key])) {
                $prefs[$key] = $saved[$key];
            }
        }
        app()->setLocale($prefs['lang']);
        $request->attributes->set('print', $prefs);
        View::share('printPrefs', $prefs);

        if ($changed) {
            Cookie::queue('print', json_encode($prefs), 60 * 24 * 365);
        }

        return $next($request);
    }

    private function valid(string $key, string $value): bool
    {
        return match ($key) {
            'lang' => array_key_exists($value, config('app.locales')),
            'size' => in_array($value, self::SIZES, true),
            default => in_array($value, ['0', '1'], true),
        };
    }
}
