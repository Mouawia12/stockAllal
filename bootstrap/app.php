<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent' => \App\Http\Middleware\AuthenticateAgent::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
        // langue choisie (cookie en clair) : avant tout le reste, pour que les pages d'erreur soient traduites aussi
        $middleware->prepend(\App\Http\Middleware\SetLocale::class);
        $middleware->encryptCookies(except: ['locale']);
        // une connexion dure 8 h (config session.lifetime), meme si le compte s'en sert encore
        $middleware->web(append: [\App\Http\Middleware\ExpireLogin::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // 419 = page restee ouverte trop longtemps (jeton CSRF perime) : retour au formulaire avec un message clair,
        // au lieu de la page "Page Expired"
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect()->back()
                ->withInput($request->except(['password', 'current_password', 'password_confirmation', '_token']))
                ->withErrors(['session' => __('common.page_expired')]);
        });
    })->create();
