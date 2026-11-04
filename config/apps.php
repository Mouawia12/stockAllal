<?php

/*
 * Applications de l'ecran d'accueil (facon Odoo), dans l'ordre d'affichage.
 * route = null : application pas encore developpee, affichee "قريباً" sans lien.
 * admin = true : visible du developpeur seulement ; can = la permission qu'il faut pour la voir.
 * Le nom de chaque cle est traduit dans lang/<langue>/common.php (apps), son icone est dans
 * resources/views/partials/app-icon.blade.php.
 */
return [
    // "لوحة القيادة" : les chiffres des ventes et des articles (App\Http\Controllers\DashboardController)
    'dashboard' => ['route' => 'dashboard.index', 'can' => 'sales.view'],
    'stock' => ['route' => 'stock.index'],
    'sales' => ['route' => 'sales.index', 'can' => 'sales.view'],
    'purchases' => ['route' => 'purchases.index', 'can' => 'sales.view'],
    'suppliers' => ['route' => 'suppliers.index', 'can' => 'sales.view'],
    'customers' => ['route' => 'customers.index', 'can' => 'sales.view'],
    // pour tous : la route "settings" mene aux comptes qui en gere (App\Support\Roles), les autres a leur propre compte
    'settings' => ['route' => 'settings'],
];
