<?php

namespace App\Support;

/**
 * Etat d'une quantite, avec les memes couleurs que la liste des articles de Gescomm (les employes les connaissent) :
 * zero = stock 0 (rouge), negative = stock < 0 (jaune), low = au seuil d'alerte ou en dessous (vert),
 * over = au-dessus du stock max (orange). Couleurs : --zero, --negative, --low, --over (partials/theme).
 * Seuil d'alerte (STOCKALERT) et stock max (STOCKMAX) : ceux de la branche, envoyes a partir de l'agent 1.0.7.
 */
final class StockState
{
    public static function of(?float $qty, ?float $alert = null, ?float $max = null): ?string
    {
        return match (true) {
            $qty === null => null,
            abs($qty) < 0.005 => 'zero',
            $qty < 0 => 'negative',
            $alert !== null && $qty <= $alert => 'low',
            $max !== null && $max > 0 && $qty > $max => 'over',
            default => null,
        };
    }
}
