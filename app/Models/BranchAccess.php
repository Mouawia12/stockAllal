<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Qui voit quoi entre les branches (table branch_access) : la branche A voit le stock de la branche B.
 * Une ligne = une permission ; sa propre branche, chacun la voit toujours (pas de ligne).
 * Une nouvelle branche voit toutes les autres et toutes la voient, comme avant les roles : on restreint ensuite.
 */
final class BranchAccess
{
    /** @var list<string> modules ou l'acces se regle ; plus tard sales, customers... */
    public const MODULES = ['stock'];

    /** @return list<int> branches que la branche $branchId voit dans ce module */
    public static function visibleFrom(int $branchId, string $module): array
    {
        return DB::table('branch_access')->where('branch_id', $branchId)->where('module', $module)
            ->pluck('visible_branch_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Remplace ce que la branche voit, seulement parmi $among (les branches que l'auteur gere) :
     * les autres acces de la branche ne changent pas.
     *
     * @param  list<int>  $visible
     * @param  list<int>  $among
     */
    public static function set(int $branchId, string $module, array $visible, array $among): void
    {
        $visible = array_values(array_diff(array_intersect($visible, $among), [$branchId]));
        DB::transaction(function () use ($branchId, $module, $visible, $among) {
            DB::table('branch_access')->where('branch_id', $branchId)->where('module', $module)
                ->whereIn('visible_branch_id', $among)->delete();
            DB::table('branch_access')->insert(array_map(
                fn (int $id) => ['branch_id' => $branchId, 'visible_branch_id' => $id, 'module' => $module], $visible));
        });
    }

    /** Nouvelle branche : elle voit toutes les autres et toutes la voient, dans chaque module. */
    public static function openNewBranch(Branch $branch): void
    {
        $others = Branch::whereKeyNot($branch->id)->pluck('id');
        $rows = [];
        foreach (self::MODULES as $module) {
            foreach ($others as $id) {
                $rows[] = ['branch_id' => $branch->id, 'visible_branch_id' => $id, 'module' => $module];
                $rows[] = ['branch_id' => $id, 'visible_branch_id' => $branch->id, 'module' => $module];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('branch_access')->insert($chunk);
        }
    }
}
