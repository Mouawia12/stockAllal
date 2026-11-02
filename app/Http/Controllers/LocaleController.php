<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Boutons عربي / FR / EN : la langue reste dans ce navigateur (cookie lu par SetLocale), puis retour a la meme page. */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(array_keys(config('app.locales')))]])['locale'];

        // lisible par public/offline.html (page statique affichee sans le serveur) : pas httpOnly
        return back()->withCookie(cookie()->forever('locale', $locale, httpOnly: false));
    }
}
