<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ViewExercise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu "السنة المالية" de la barre du haut : ouvrir un exercice clos (exercise_id) ou revenir a celui en cours (vide),
 * puis retour a la meme page. Un POST, comme le selecteur de branche (public/sw.js rejoue les GET).
 */
class ExerciseController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $id = $request->validate(['exercise_id' => ['nullable', 'integer']])['exercise_id'] ?? null;
        if ($id === null) {
            $request->session()->forget(ViewExercise::KEY);

            return back();
        }
        abort_if(ViewExercise::visible($request, (int) $id) === null, 404);
        $request->session()->put(ViewExercise::KEY, (int) $id);

        return back();
    }
}
