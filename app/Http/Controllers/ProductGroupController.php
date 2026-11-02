<?php

namespace App\Http\Controllers;

use App\Models\BranchStock;
use App\Models\ProductGroup;
use App\Models\ProductGroupCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Page "دمج الأصناف" (administrateur) : cocher des codes qui sont le meme produit sous des ecritures differentes
 * (820AC dans une branche, 820-AC dans une autre) et les fusionner en un groupe. La page elle-meme est StockController::merge.
 */
class ProductGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codes' => ['required', 'array'],
            'codes.*' => ['required', 'string', 'max:30'],
        ], [
            'codes.required' => __('stock.merge.pick_two'),
        ]);
        $keys = collect($data['codes'])->map(fn (string $code) => BranchStock::keyFor($code))->unique()->values();
        if ($keys->count() < 2) {
            return back()->withErrors(['codes' => __('stock.merge.pick_two')]);
        }

        $codes = DB::transaction(function () use ($keys, $request) {
            $existing = ProductGroupCode::whereIn('code_key', $keys)->lockForUpdate()->get();
            $groupIds = $existing->pluck('product_group_id')->unique()->sort()->values();
            if ($groupIds->count() === 1 && $existing->count() === $keys->count()) {
                return null;   // deja tous dans le meme groupe
            }

            // les groupes deja existants rejoignent le plus ancien, les codes isoles y sont ajoutes
            $group = $groupIds->isEmpty()
                ? ProductGroup::create(['created_by' => $request->user()->id])
                : ProductGroup::findOrFail($groupIds->first());
            $others = $groupIds->slice(1)->values();
            ProductGroupCode::whereIn('product_group_id', $others)->update(['product_group_id' => $group->id]);
            ProductGroup::whereIn('id', $others)->delete();
            foreach ($keys->diff($existing->pluck('code_key')) as $key) {
                ProductGroupCode::create(['product_group_id' => $group->id, 'code_key' => $key]);
            }

            return ProductGroup::codesOf($keys->first());
        });

        if ($codes === null) {
            return back()->withErrors(['codes' => __('stock.merge.already')]);
        }

        // LRI ... PDI : sans isolation, "820-AC + 820AC" s'affiche en desordre dans la phrase arabe
        return back()->with('status', __('stock.merge.done', ['codes' => "\u{2066}".implode(' + ', $codes)."\u{2069}"]));
    }

    /** Retire un code de son groupe ; un groupe qui n'a plus qu'un code est supprime. */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $key = BranchStock::keyFor($data['code']);

        DB::transaction(function () use ($key) {
            $code = ProductGroupCode::where('code_key', $key)->lockForUpdate()->first();
            if (! $code) {
                return;
            }
            $code->delete();
            if (ProductGroupCode::where('product_group_id', $code->product_group_id)->count() < 2) {
                ProductGroupCode::where('product_group_id', $code->product_group_id)->delete();
                ProductGroup::whereKey($code->product_group_id)->delete();
            }
        });

        return back()->with('status', __('stock.merge.removed', ['code' => $key]));
    }
}
