<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\Actions\SignIn;
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $signIn->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            (string) $request->ip(),
        );

        $request->session()->regenerate();
        SignInMethod::markPassword($request);

        return $me($request);
    }
}
