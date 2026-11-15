<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signs a person in with an email address and a password.
 *
 * - One message for every failure, so the response never confirms that an
 *   account exists or that it is disabled.
 * - Five failed attempts for one email address from one address lock that
 *   pair for one minute.
 */
final class SignIn
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_SECONDS = 60;

    public function __construct(private readonly Clock $clock) {}

    public function handle(string $email, string $password, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $key = 'sign-in:'.sha1($email.'|'.$ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException('Too many sign-in attempts.');
        }

        $signedIn = Auth::guard('web')->attempt([
            'email' => $email,
            'password' => $password,
            'status' => AccountStatus::Active->value,
        ]);

        $user = Auth::guard('web')->user();

        if (! $signedIn || ! $user instanceof User) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['The email address or password is incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        $user->forceFill(['last_signed_in_at' => $this->clock->now()])->save();

        return $user;
    }
}
