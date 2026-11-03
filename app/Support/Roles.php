<?php

namespace App\Support;

/**
 * Roles des comptes (users.role), du plus fort au plus faible (docs/roadmap.md) :
 * - developer : tout, toutes les branches ; seul a creer un directeur de la societe et a choisir ses branches.
 * - owner (مدير الشركة) : ses branches ; cree et gere les directeurs de branche et leurs employes.
 * - branch_manager (مدير الفرع) : sa branche ; cree et gere les employes de sa branche.
 * - user : sa branche, et les permissions cochees pour lui.
 * Gerer les comptes depend du role, jamais d'une case : personne ne donne un role, une branche ou une permission
 * qu'il n'a pas (User::canManage, assignableRoles, grantablePermissions).
 */
final class Roles
{
    public const DEVELOPER = 'developer';

    public const OWNER = 'owner';

    public const BRANCH_MANAGER = 'branch_manager';

    public const USER = 'user';

    /** @var list<string> du plus fort au plus faible ; textes : lang/<langue>/users.php (roles) */
    public const ALL = [self::DEVELOPER, self::OWNER, self::BRANCH_MANAGER, self::USER];

    /** Roles qu'un role peut donner, a la creation comme a la modification d'un compte. */
    public const MANAGES = [
        self::DEVELOPER => [self::DEVELOPER, self::OWNER, self::BRANCH_MANAGER, self::USER],
        self::OWNER => [self::BRANCH_MANAGER, self::USER],
        self::BRANCH_MANAGER => [self::USER],
        self::USER => [],
    ];

    /** Ces roles appartiennent a une branche (users.branch_id) ; developer et owner n'en ont pas. */
    public static function inBranch(string $role): bool
    {
        return in_array($role, [self::BRANCH_MANAGER, self::USER], true);
    }
}
