<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Bons, lignes et versements : ceux de l'exercice regarde. 0 (par defaut) = l'exercice en cours de chaque branche,
 * comme Gescomm qui ne montre que sa base ; un id = un exercice clos ouvert dans le menu (App\Http\Middleware\
 * ViewExercise, le temps d'une requete). Le rapprochement entre branches l'enleve : il lit tous les exercices par date.
 */
final class ExerciseScope implements Scope
{
    public static int $viewing = 0;

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('exercise_id'), self::$viewing);
    }
}
