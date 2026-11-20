<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\Actions\SignIn;
use Acadly\Kernel\Identity\Actions\TwoStep;
use Acadly\Kernel\Identity\SignInMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SignInController
{
    /**
     * Sign in.
     *
     * Starts a session for the account. The response is the same as GET /me.
     */
    public function __invoke(Request $request, SignIn $signIn, MeController $me): JsonResponse
    {
        $request->validate([
            // An email address, or a student number for a student account (D-101).
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $signIn->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            (string) $request->ip(),
        );

        // The password was right; the second step is asked for (D-089).
        if ($user->two_step_on_at !== null) {
            return response()->json(['two_step' => true], 202);
        }

        $request->session()->regenerate();
        SignInMethod::markPassword($request);

        return $me($request);
    }

    /**
     * The second step of a sign-in: a code from the authenticator app, or a recovery code.
     *
     * The response is the same as GET /me.
     */
    public function twoStep(Request $request, TwoStep $twoStep, MeController $me): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $twoStep->signIn($request, $request->string('code')->toString());
        $request->session()->regenerate();
        SignInMethod::markPassword($request);

        return $me($request);
    }
}
