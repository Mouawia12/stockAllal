<?php

/*
 * Applications de l'ecran d'accueil (facon Odoo), dans l'ordre d'affichage.
 * route = null : application pas encore developpee, affichee "قريباً" sans lien.
 * admin = true : visible des administrateurs seulement.
 * Le nom de chaque cle est traduit dans lang/<langue>/common.php (apps), son icone est dans
 * resources/views/partials/app-icon.blade.php.
 */
return [
    'stock' => ['route' => 'stock.index'],
    'sales' => ['route' => null],
    'purchases' => ['route' => null],
    'suppliers' => ['route' => null],
    'customers' => ['route' => null],
    // pour tous : la route "settings" mene un administrateur aux comptes, les autres a leur propre compte
    'settings' => ['route' => 'settings'],
];
