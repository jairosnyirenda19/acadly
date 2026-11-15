<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\Actions\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class PasswordResetController
{
    /**
     * Request a password reset link.
     *
     * The response is the same whether or not an account exists for the
     * address.
     *
     * @response 202 array{status: 'accepted', message: string}
     */
    public function requestLink(Request $request, ResetPassword $reset): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $reset->requestLink($request->string('email')->toString());

        return response()->json([
            'status' => 'accepted',
            'message' => 'If an account exists for this email address, a reset link has been sent.',
        ], 202);
    }

    /**
     * Set a new password with a reset link.
     */
    public function reset(Request $request, ResetPassword $reset): Response
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $reset->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return response()->noContent();
    }
}
