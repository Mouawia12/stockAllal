<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Carbon;

/**
 * Bons, lignes et versements : ceux de l'exercice regarde. 0 (par defaut) = l'exercice en cours de chaque branche,
 * comme Gescomm qui ne montre que sa base ; un id = un exercice clos ouvert dans le menu (App\Http\Middleware\
 * ViewExercise, le temps d'une requete). Le rapprochement entre branches l'enleve : il lit tous les exercices par date.
 *
 * Un exercice clos ouvert a aussi son "aujourd'hui" (asOf, son dernier jour de bons) : les periodes des pages ("30
 * derniers jours", "12 mois", "aujourd'hui" du tableau de bord) se comptent depuis ce jour-la avec now() ci-dessous ;
 * comptees depuis la vraie date, elles ne contiendraient rien d'un exercice clos depuis des mois.
 */
final class ExerciseScope implements Scope
{
    public static int $viewing = 0;

    public static ?string $asOf = null;

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('exercise_id'), self::$viewing);
    }

    /** Maintenant, pour les periodes des pages : la fin du dernier jour de l'exercice clos ouvert, sinon maintenant. */
    public static function now(): Carbon
    {
        return self::$asOf === null ? now() : Carbon::parse(self::$asOf)->endOfDay();
    }

    /** A ajouter aux cles de cache de ce qui se calcule sur des bons ou des versements. */
    public static function cacheKey(): string
    {
        return self::$viewing === 0 ? '' : ':ex'.self::$viewing;
    }
}
