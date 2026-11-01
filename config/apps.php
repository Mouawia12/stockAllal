<?php

/*
 * Applications de l'ecran d'accueil (facon Odoo), dans l'ordre d'affichage.
 * route = null : application pas encore developpee, affichee "قريباً" sans lien.
 * admin = true : visible des administrateurs seulement.
 * L'icone de chaque cle est dans resources/views/partials/app-icon.blade.php.
 */
return [
    'stock' => ['name' => 'المخزون', 'route' => 'stock.index'],
    'sales' => ['name' => 'المبيعات', 'route' => null],
    'purchases' => ['name' => 'المشتريات', 'route' => null],
    'suppliers' => ['name' => 'الموردون', 'route' => null],
    'customers' => ['name' => 'الزبائن', 'route' => null],
    'settings' => ['name' => 'الإعدادات', 'route' => 'users.index', 'admin' => true],
];
