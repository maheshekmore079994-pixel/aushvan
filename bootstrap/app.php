<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\Idempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The React screens are served from the same origin and use the session cookie.
        $middleware->statefulApi();

        // A signed-out visitor opening a link directly is sent to the app, which shows the sign-in screen.
        $middleware->redirectGuestsTo('/');

        $middleware->alias([
            'idempotent' => Idempotency::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
