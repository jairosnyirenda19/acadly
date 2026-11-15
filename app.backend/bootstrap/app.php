<?php

use Acadly\Kernel\Http\Middleware\RemoveServerSignature;
use Acadly\Kernel\Http\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RemoveServerSignature::class);

        // The web apps sign in with a session cookie (Sanctum, stateful).
        $middleware->statefulApi();

        // An API has no sign-in page: a guest gets 401, never a redirect.
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error is a problem document. It never discloses internals.
        $exceptions->render(fn (Throwable $e) => (new ProblemRenderer)->render($e));
    })->create();
