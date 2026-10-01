<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // PRIORITÉ 4 §14 : le contrôle de disponibilité par défaut de
            // Laravel ne teste que « PHP a démarré ». Une base injoignable
            // — la panne bloquante ici — ne serait pas détectée.
            Route::get('/up', [HealthController::class, 'live'])->name('health.live');
            Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'merchant' => \App\Http\Middleware\EnsureUserIsMerchant::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            // Source de vérité unique : User::is_blocked.
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'can-create-tontine' => \App\Http\Middleware\EnsureCanCreateTontine::class,
        ]);

        // PRIORITÉ 4 §18 : la confiance envers les proxies se règle par .env
        // (TRUSTED_PROXIES), jamais en dur. `env()` et non `config()` : ce
        // fichier s'exécute pendant la construction de l'application, avant
        // que le dépôt de configuration ne soit chargeable.
        $proxies = (string) env('TRUSTED_PROXIES', '');

        if (trim($proxies) === '*') {
            $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        } elseif (trim($proxies) !== '') {
            $middleware->trustProxies(
                at: array_values(array_filter(array_map('trim', explode(',', $proxies)))),
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->create();
