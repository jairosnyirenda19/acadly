<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\Actions\AccountPhone;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The phone number on a member of staff's own account (D-087). */
final class AccountPhoneController
{
    /**
     * Send a code to add a phone number.
     *
     * Sends a six-digit code by SMS to a number the school keeps for a
     * learner's contact, in English or Chichewa (`language`: `en` or `ny`).
     * Needs a sign-in with the email address and password.
     *
     * @response array{sent: true}
     */
    public function code(Request $request, AccountPhone $phone): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'language' => ['nullable', 'string', 'in:en,ny']]);
        $phone->sendCode(self::user($request), $request->string('phone')->toString(), (string) $request->ip(), $request->string('language', 'en')->toString());

        return response()->json(['sent' => true]);
    }

    /**
     * Add the phone number with its code.
     *
     * The parent portal then opens this account. A parent account already on
     * the number is closed and its children move here. The response is the
     * same as GET /me.
     */
    public function confirm(Request $request, AccountPhone $phone, MeController $me): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'code' => ['required', 'string', 'max:12']]);
        $phone->confirm(self::user($request), $request->string('phone')->toString(), $request->string('code')->toString());

        return $me($request);
    }

    /**
     * Remove the phone number.
     *
     * The parent portal no longer opens this account; the number signs in to
     * an account of its own. The response is the same as GET /me.
     */
    public function remove(Request $request, AccountPhone $phone, MeController $me): JsonResponse
    {
        $phone->remove(self::user($request));

        return $me($request);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
