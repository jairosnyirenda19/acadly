<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Audit\Watch;
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
    public function __construct(private readonly Audit $audit) {}

    public function requestLink(string $email): void
    {
        $email = mb_strtolower(trim($email));

        Password::broker()->sendResetLink([
            'email' => $email,
            'status' => AccountStatus::Active->value,
        ]);

        $this->audit->record(KernelAudit::ResetRequested, subject: User::query()->where('email', $email)->first(),
            actor: Actor::anonymous(), personal: ['email' => $email]);
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

                $watch = Watch::of($user);

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->audit->record(KernelAudit::PasswordReset, subject: $user, changes: $watch->diff(), actor: $this->audit->actorFor($user));

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit->record(KernelAudit::PasswordReset, outcome: Outcome::Failed, refusal: 'reset_link_invalid',
                actor: Actor::anonymous(), personal: ['email' => mb_strtolower(trim($email))]);

            throw ValidationException::withMessages([
                'token' => ['The reset link is not valid or has expired.'],
            ]);
        }
    }
}
