<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http\Middleware;

use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\SignInMethod;
use Acadly\Kernel\Identity\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session signed in with a code sent by SMS reaches the parent portal
 * and the account itself, nothing else (D-087). A member of staff who is
 * also a parent signs in with the email address and password for the
 * console. The parent portal's routes are named `portal.*`.
 */
final class CodeSignInReachesPortalOnly
{
    /** Routes a code session may use besides the parent portal's. */
    private const array ALLOWED = [
        'me', 'auth.sign-out', 'auth.sign-in', 'auth.phone.code', 'auth.phone.sign-in', 'auth.forgot-password', 'auth.reset-password',
        'sanctum.csrf-cookie', 'kernel.health', 'kernel.ready', 'kernel.school', 'institution.logo.public', 'notify.reports',
    ];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        // The account this request runs as; the route's own sign-in check has resolved it.
        $user = $request->user() ?? $guard->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Brought back by the remember cookie: only a sign-in by code is remembered.
        if ($guard->viaRemember() && $guard->user()?->is($user) === true) {
            SignInMethod::markCode($request, $user);
        }

        $name = (string) $request->route()?->getName();

        if (SignInMethod::byCode($request, $user) && ! str_starts_with($name, 'portal.') && ! in_array($name, self::ALLOWED, true)) {
            throw Problem::passwordRequired();
        }

        return $next($request);
    }
}
