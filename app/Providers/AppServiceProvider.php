<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // "منذ 3 دقائق" dans diffForHumans()
        Carbon::setLocale(config('app.locale'));

        // Limiteurs nommes, chacun avec sa propre cle : un "throttle:5,1" anonyme partage un seul compteur
        // par IP entre toutes les routes, et l'agent d'une branche bloquerait la connexion depuis le meme bureau.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by('login|'.$request->ip()));
        RateLimiter::for('agent-register', fn (Request $request) => Limit::perMinute(10)->by('register|'.$request->ip()));
        // 1.0.7 envoie l'historique par pages a la premiere installation (une requete toutes les ~2 s) en plus des demandes en direct
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(300)->by('agent|'.sha1((string) $request->bearerToken())));
        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute(30)->by('refresh|'.($request->user()?->id ?? $request->ip())));
        // recherche pendant la frappe : quelques requetes par mot tape, large pour un humain, borne un script
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(240)->by('search|'.($request->user()?->id ?? $request->ip())));

        // une Gate par permission cochable (App\Support\Permissions) : routes "can:...", @can dans les vues
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        // roles, jamais des cases (App\Support\Roles) : la page des comptes, et qui voit le stock de quelle branche
        Gate::define('manage-users', fn (User $user) => $user->managesAccounts());
        Gate::define('manage-branch-access', fn (User $user) => $user->isDeveloper() || $user->isOwner());
        Gate::define('view-activity', fn (User $user) => $user->isDeveloper());
    }
}
