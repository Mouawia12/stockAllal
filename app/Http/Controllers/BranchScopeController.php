<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Selecteur de branche de la barre du haut : une branche, ou "كل فروعي" (branch_id vide), puis retour a la meme page.
 * Un POST, comme les boutons de langue : public/sw.js rejoue les GET avec un Referer "/sw.js".
 */
class BranchScopeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $id = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        if ($id === null) {
            $request->session()->forget(BranchScope::KEY);

            return back();
        }

        $branch = Branch::where('active', true)->findOrFail($id);
        abort_unless($request->user()->seesBranch($branch), 404);
        $request->session()->put(BranchScope::KEY, $branch->id);

        return back();
    }
}
