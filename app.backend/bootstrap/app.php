<?php

use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Http\Middleware\CodeSignInReachesPortalOnly;
use Acadly\Kernel\Http\Middleware\EndViewAsWhenDue;
use Acadly\Kernel\Http\Middleware\RemoveServerSignature;
use Acadly\Kernel\Http\Middleware\StudentAccountsReachStudentPortalOnly;
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

        // The place the console works in (D-099), for requests that cannot name it in a header
        // (a receipt opened in a new tab, a photo). Only a place key; the server checks it like the header.
        $middleware->encryptCookies(except: [Boundary::COOKIE]);

        // View as (development only) ends on time, before the request is handled.
        $middleware->api(append: [EndViewAsWhenDue::class, CodeSignInReachesPortalOnly::class, StudentAccountsReachStudentPortalOnly::class]);

        // An API has no sign-in page: a guest gets 401, never a redirect.
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error is a problem document. It never discloses internals.
        $exceptions->render(fn (Throwable $e) => (new ProblemRenderer)->render($e));
    })->create();
