<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Illuminate\Http\Request;

/**
 * How the session was signed in (D-087). A code sent by SMS opens the parent
 * portal only; everything else needs the email address and password.
 *
 * The mark names the account that signed in by code, so it never carries
 * over to another account in the same session. Only a sign-in by code is
 * remembered on the device, so a session the remember cookie brings back
 * is a code session too.
 */
final class SignInMethod
{
    public const string BY_CODE = 'acadly.signed_in_by_code';

    public static function markCode(Request $request, User $user): void
    {
        $request->session()->put(self::BY_CODE, $user->id);
    }

    public static function markPassword(Request $request): void
    {
        $request->session()->forget(self::BY_CODE);
    }

    public static function byCode(Request $request, User $user): bool
    {
        return $request->hasSession() && $request->session()->get(self::BY_CODE) === $user->id;
    }
}
