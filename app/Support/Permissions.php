<?php

namespace App\Support;

/**
 * Permissions qu'un administrateur coche pour un compte (users.permissions, JSON).
 * Un administrateur (users.is_admin) les a toutes, plus la gestion des comptes, qui n'est jamais une case :
 * un compte qui pourrait gerer les comptes pourrait se donner tous les droits.
 * Chaque permission est une Gate (AppServiceProvider) : middleware can:..., @can dans les vues, $user->can().
 * Rechercher dans le stock et gerer son propre compte : toujours permis a tout compte connecte.
 */
final class Permissions
{
    /** Groupes de la page d'un compte, dans l'ordre d'affichage. Textes : lang/<langue>/users.php (perms). */
    public const GROUPS = [
        'stock' => ['stock.refresh', 'prices.sale', 'prices.buy'],
        'branches' => ['branches.view', 'branches.sync', 'branches.manage', 'agent.download'],
        'products' => ['merge.manage'],
    ];

    /** Cochees pour un nouveau compte, et donnees aux employes qui existaient avant les permissions. */
    public const DEFAULTS = ['stock.refresh', 'prices.sale', 'branches.view', 'branches.sync'];

    /** Ces permissions passent par la page des succursales : elles entrainent "branches.view". */
    public const NEED_BRANCHES_VIEW = ['branches.sync', 'branches.manage', 'agent.download'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Liste propre a enregistrer : permissions connues seulement, dans l'ordre de GROUPS, avec les dependances.
     *
     * @param  iterable<mixed>|null  $permissions
     * @return list<string>
     */
    public static function normalize(?iterable $permissions): array
    {
        $given = collect($permissions ?? [])->map(fn ($p) => (string) $p)->all();
        if (array_intersect($given, self::NEED_BRANCHES_VIEW) !== []) {
            $given[] = 'branches.view';
        }

        return array_values(array_filter(self::all(), fn (string $p) => in_array($p, $given, true)));
    }
}
