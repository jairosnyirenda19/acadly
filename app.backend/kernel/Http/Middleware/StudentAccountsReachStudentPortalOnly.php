<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http\Middleware;

use Acadly\Kernel\Identity\StudentAccounts;
use Acadly\Kernel\Identity\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A student account reaches the student portal and its own account, nothing
 * else (D-101): never the console, whatever it is given. The student
 * portal's routes are named `student.*`.
 */
final class StudentAccountsReachStudentPortalOnly
{
    /** Routes a student account may use besides the student portal's. */
    private const array ALLOWED = [
        'me', 'me.account', 'me.account.password', 'me.account.two-step', 'me.account.two-step.confirm', 'me.account.two-step.off',
        'auth.sign-out', 'auth.sign-in', 'auth.two-step', 'auth.forgot-password', 'auth.reset-password',
        'sanctum.csrf-cookie', 'kernel.health', 'kernel.ready', 'kernel.school', 'institution.logo.public',
    ];

    public function __construct(private readonly StudentAccounts $students) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? Auth::guard('web')->user();
        $name = (string) $request->route()?->getName();

        if ($user instanceof User && ! str_starts_with($name, 'student.') && ! in_array($name, self::ALLOWED, true) && $this->students->isStudent($user->id)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
