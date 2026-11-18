<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\Actions\SendSignInCode;
use Acadly\Kernel\Identity\Actions\SignInWithCode;
use Acadly\Kernel\Identity\SignInMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sign-in by phone number and a code sent by SMS (D-082). */
final class PhoneSignInController
{
    /**
     * Send a sign-in code.
     *
     * Sends a six-digit code by SMS when the school keeps this number. The
     * answer is the same either way. `language` is `en` or `ny`.
     *
     * @response array{sent: true}
     */
    public function code(Request $request, SendSignInCode $send): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'language' => ['nullable', 'string', 'in:en,ny'],
        ]);

        $send->handle($request->string('phone')->toString(), (string) $request->ip(), $request->string('language', 'en')->toString());

        return response()->json(['sent' => true]);
    }

    /**
     * Sign in with a code.
     *
     * Starts a session for the account of this number. The phone stays
     * signed in for 90 days. The response is the same as GET /me.
     */
    public function signIn(Request $request, SignInWithCode $signIn, MeController $me): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $signIn->handle($request->string('phone')->toString(), $request->string('code')->toString(), (string) $request->ip());

        $request->session()->regenerate();
        // A code opens the parent portal only (D-087).
        SignInMethod::markCode($request, $user);

        return $me($request);
    }
}
