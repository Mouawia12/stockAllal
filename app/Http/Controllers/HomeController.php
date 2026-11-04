<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ecran d'accueil apres connexion : les applications (config/apps.php), facon Odoo. */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // une application que le compte ne peut pas ouvrir n'apparait pas (ex. les ventes sans sales.view)
        return view('home', [
            'apps' => collect(config('apps'))->filter(fn (array $app) => ($user->isDeveloper() || empty($app['admin']))
                && (empty($app['can']) || $user->can($app['can']))),
        ]);
    }
}
