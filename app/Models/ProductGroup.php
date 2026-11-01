<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Codes fusionnes par l'administrateur (page "دمج الأصناف") : le meme produit ecrit differemment selon la branche.
 * La recherche, la fenetre "معاينة" et "تحديث الآن" traitent un groupe comme un seul produit.
 */
#[Fillable(['created_by'])]
class ProductGroup extends Model
{
    public function codes(): HasMany
    {
        return $this->hasMany(ProductGroupCode::class);
    }

    /**
     * Pour chaque code_key, les codes qu'il represente : tout son groupe (trie), ou lui seul s'il n'est pas fusionne.
     *
     * @param  iterable<string>  $keys
     * @return array<string, list<string>>
     */
    public static function expand(iterable $keys): array
    {
        $keys = collect($keys)->map(fn ($key) => (string) $key)->unique()->values();
        $groupOf = ProductGroupCode::whereIn('code_key', $keys)->pluck('product_group_id', 'code_key');
        $members = ProductGroupCode::whereIn('product_group_id', $groupOf->unique()->values())
            ->orderBy('code_key')
            ->get()
            ->groupBy('product_group_id')
            ->map(fn ($codes) => $codes->pluck('code_key')->map(fn ($key) => (string) $key)->all());

        $expanded = [];
        foreach ($keys as $key) {
            $expanded[$key] = $groupOf->has($key) ? $members[$groupOf[$key]] : [$key];
        }

        return $expanded;
    }

    /** @return list<string> */
    public static function codesOf(string $key): array
    {
        return self::expand([$key])[$key];
    }
}
