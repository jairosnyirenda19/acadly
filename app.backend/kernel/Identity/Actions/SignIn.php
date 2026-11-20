<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\StudentAccounts;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

/**
 * Signs a person in with an email address and a password.
 *
 * - One message for every failure, so the response never confirms that an
 *   account exists or that it is disabled.
 * - Five failed attempts for one email address from one address lock that
 *   pair for one minute.
 * - Every attempt is an audit line. The address typed is personal data and
 *   goes to the encrypted side record.
 */
final class SignIn
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly StudentAccounts $students,
    ) {}

    /** Signs in with an email address, or with a student number (D-101). */
    public function handle(string $email, string $password, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $key = 'sign-in:'.sha1($email.'|'.$ip);
        // A student may sign in with the student number instead of an email address.
        $byNumber = ! str_contains($email, '@') ? $this->students->accountForNumber($email) : null;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->audit->record(KernelAudit::SignInPaused, subject: $this->account($email), outcome: Outcome::Refused,
                refusal: 'sign_in_paused', actor: Actor::anonymous(), personal: ['email' => $email]);

            throw new ThrottleRequestsException('Too many sign-in attempts.');
        }

        $who = $byNumber !== null ? ['id' => $byNumber] : ['email' => str_contains($email, '@') ? $email : "\u{0}"];
        $credentials = [...$who, 'password' => $password, 'status' => AccountStatus::Active->value];
        $signedIn = Auth::guard('web')->validate($credentials);
        $user = $signedIn ? User::query()->where(array_key_first($who), reset($who))->first() : null;

        if (! $signedIn || ! $user instanceof User) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            $this->audit->record(KernelAudit::SignInFailed, subject: $this->account($email), outcome: Outcome::Failed,
                actor: Actor::anonymous(), personal: ['email' => $email]);

            throw ValidationException::withMessages([
                'email' => ['The email address or password is incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        // Two-step sign-in on: the code finishes the sign-in (D-089).
        if ($user->two_step_on_at !== null) {
            Session::put(TwoStep::PENDING, ['user' => $user->id, 'until' => $this->clock->now()->addMinutes(5)->getTimestamp()]);
            $this->audit->record(KernelAudit::TwoStepAsked, subject: $user, actor: $this->audit->actorFor($user));

            return $user;
        }

        Auth::guard('web')->login($user);
        $user->forceFill(['last_signed_in_at' => $this->clock->now()])->save();

        $this->audit->record(KernelAudit::SignedIn, subject: $user, actor: $this->audit->actorFor($user));

        return $user;
    }

    /** The account an attempt names, if there is one. Never shown to the person signing in. */
    private function account(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }
}
