<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset, in two steps. Requesting a link never says whether the
 * account exists. Using the link sets a new password and ends every
 * remembered sign-in on other devices.
 */
final class ResetPassword
{
    public function requestLink(string $email): void
    {
        Password::broker()->sendResetLink([
            'email' => mb_strtolower(trim($email)),
            'status' => AccountStatus::Active->value,
        ]);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            [
                'email' => mb_strtolower(trim($email)),
                'status' => AccountStatus::Active->value,
                'token' => $token,
                'password' => $password,
            ],
            function (CanResetPassword $user, string $password): void {
                if (! $user instanceof User) {
                    return;
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['The reset link is not valid or has expired.'],
            ]);
        }
    }
}
