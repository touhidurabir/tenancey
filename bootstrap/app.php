<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Guests and signed-in users are redirected within their own world: tenant routes run
        // with tenancy initialized, central routes without.
        $middleware->redirectGuestsTo(fn () => tenancy()->initialized ? route('login') : route('central.login'));
        $middleware->redirectUsersTo(fn () => tenancy()->initialized ? route('dashboard') : route('central.tenants.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
