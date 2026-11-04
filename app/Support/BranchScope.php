<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Selecteur de branche de la barre du haut, facon Odoo : "كل فروعي" (toutes celles que le compte voit) ou une seule.
 * Le choix reste dans la session ; il ne donne jamais plus que User::stockBranchIds() : une branche choisie
 * puis retiree au compte est ignoree. Les pages du stock passent par ids() a la place de stockBranchIds().
 */
final class BranchScope
{
    public const KEY = 'branch_scope';

    /** @return Collection<int, Branch> branches actives que le compte peut choisir */
    public static function choices(User $user): Collection
    {
        return Branch::where('active', true)->visibleTo($user)->orderBy('name')->get();
    }

    /** Branche choisie dans la barre du haut, si elle est encore visible pour ce compte ; null = toutes. */
    public static function selected(Request $request): ?Branch
    {
        $id = $request->session()->get(self::KEY);
        if ($id === null || $request->user() === null) {
            return null;
        }
        $branch = Branch::where('active', true)->find($id);

        return $branch && $request->user()->seesBranch($branch) ? $branch : null;
    }

    /** @return list<int>|null branches a afficher (null = toutes) : la branche choisie, sinon celles du compte */
    public static function ids(Request $request): ?array
    {
        $selected = self::selected($request);

        return $selected ? [$selected->id] : $request->user()->stockBranchIds();
    }

    /**
     * Branches dont les listes de bons montrent les ventes et achats : la branche choisie si le compte en voit
     * les ventes (aucune sinon), ou toutes celles de User::salesBranchIds().
     *
     * @return list<int>|null
     */
    public static function salesIds(Request $request): ?array
    {
        $allowed = $request->user()->salesBranchIds();
        $selected = self::selected($request);
        if ($selected === null) {
            return $allowed;
        }

        return $allowed === null || in_array($selected->id, $allowed, true) ? [$selected->id] : [];
    }
}
