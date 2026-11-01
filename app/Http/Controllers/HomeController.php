<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ecran d'accueil apres connexion : les applications (config/apps.php), facon Odoo. */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $isAdmin = (bool) $request->user()->is_admin;

        return view('home', [
            'apps' => collect(config('apps'))->filter(fn (array $app) => $isAdmin || empty($app['admin'])),
        ]);
    }
}
