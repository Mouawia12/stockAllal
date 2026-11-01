<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class Controller
{
    /**
     * Apres un nouveau mot de passe (page حسابي ou page des comptes) : les sessions deja ouvertes avec ce compte
     * (autre PC, telephone) sont fermees et l'ancien cookie "se souvenir de moi" ne reconnecte plus personne.
     * La session courante est gardee.
     */
    protected function closeOtherSessions(Request $request, User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        // son propre compte, connecte avec "تذكّرني" : ce navigateur recoit un cookie avec la nouvelle cle
        if ($user->is($request->user()) && $request->cookies->has(Auth::guard()->getRecallerName())) {
            Auth::login($user, true);
        }
    }
}
