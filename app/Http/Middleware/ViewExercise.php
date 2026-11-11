<?php

namespace App\Http\Middleware;

use App\Models\Exercise;
use App\Models\Scopes\ExerciseScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un exercice clos ouvert dans le menu "السنة المالية" (ExerciseController, session) : toutes les pages montrent ses
 * bons et ses versements, comme si l'on ouvrait sa base dans Gescomm, jusqu'au retour a l'exercice en cours. Seulement
 * pour un compte qui voit les ventes de sa branche ; sinon (droits retires) on revient a l'exercice en cours.
 */
class ViewExercise
{
    public const KEY = 'exercise';

    public function handle(Request $request, Closure $next): Response
    {
        ExerciseScope::$viewing = 0;
        View::share('viewingExercise', null);
        $id = $request->hasSession() ? $request->session()->get(self::KEY) : null;
        if ($id !== null) {
            $exercise = self::visible($request, (int) $id);
            if ($exercise === null) {
                $request->session()->forget(self::KEY);
            } else {
                ExerciseScope::$viewing = $exercise->id;
                View::share('viewingExercise', $exercise);
            }
        }

        return $next($request);
    }

    /** La requete suivante (un test en enchaine plusieurs) repart de l'exercice en cours. */
    public function terminate(Request $request, Response $response): void
    {
        ExerciseScope::$viewing = 0;
    }

    /** Un exercice clos dont le compte voit les ventes de la branche (active). */
    public static function visible(Request $request, int $id): ?Exercise
    {
        $user = $request->user();
        $exercise = $user === null ? null : Exercise::with('branch')->where('current', false)->find($id);
        if ($exercise === null || ! $exercise->branch?->active || ! $user->can('sales.view')) {
            return null;
        }
        $allowed = $user->salesBranchIds();

        return $allowed === null || in_array($exercise->branch_id, $allowed, true) ? $exercise : null;
    }
}
