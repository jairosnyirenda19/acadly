<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\PhoneHolders;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signs a person in with a phone number and the code sent to it (D-082).
 *
 * - The newest live code for the number is the only one that counts. Five
 *   wrong tries end it; ten wrong tries from one address in ten minutes
 *   pause that address.
 * - The first sign-in makes the account: the name the school has for the
 *   number, no email address, no usable password. It holds no role.
 * - The phone stays signed in for 90 days (a remember cookie).
 */
final class SignInWithCode
{
    public const int MAX_TRIES = 5;

    public const int REMEMBER_MINUTES = 90 * 24 * 60;

    public function __construct(
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly PhoneHolders $holders,
    ) {}

    public function handle(string $written, string $code, string $ip): User
    {
        $phone = Phone::international($written) ?? throw self::failed();
        $code = (string) preg_replace('/\D/', '', $code);
        $pause = 'sign-in-code-try:'.$ip;

        if (RateLimiter::tooManyAttempts($pause, 10)) {
            $this->audit->record(KernelAudit::SignInPaused, outcome: Outcome::Refused, refusal: 'sign_in_paused',
                actor: Actor::anonymous(), personal: ['phone' => $phone]);

            throw new ThrottleRequestsException('Too many sign-in attempts.');
        }

        $now = $this->clock->now();

        $user = DB::transaction(function () use ($phone, $code, $now, $pause): ?User {
            $row = DB::table('sign_in_codes')->where('phone', $phone)->whereNull('used_at')->where('expires_at', '>', $now)
                ->orderByDesc('created_at')->lockForUpdate()->first(['id', 'code_hash', 'attempts']);

            if ($row === null || Row::int($row, 'attempts') >= self::MAX_TRIES) {
                RateLimiter::hit($pause, 600);

                return null;
            }

            if (! hash_equals(Row::text($row, 'code_hash'), SendSignInCode::hash($phone, $code))) {
                DB::table('sign_in_codes')->where('id', $row->id)->increment('attempts');
                RateLimiter::hit($pause, 600);

                return null;
            }

            DB::table('sign_in_codes')->where('id', $row->id)->update(['used_at' => $now]);

            return $this->account($phone);
        });

        if (! $user instanceof User || ! $user->isActive()) {
            $this->audit->record(KernelAudit::SignInFailed, subject: $user, outcome: Outcome::Failed,
                actor: Actor::anonymous(), personal: ['phone' => $phone]);

            throw self::failed();
        }

        $guard = Auth::guard('web');

        $guard->setRememberDuration(self::REMEMBER_MINUTES);

        $guard->login($user, true);
        $user->forceFill(['last_signed_in_at' => $now])->save();

        $this->audit->record(KernelAudit::SignedIn, subject: $user, actor: $this->audit->actorFor($user), meta: ['by' => 'phone_code']);

        return $user;
    }

    /** The account for this number; the first sign-in makes it. */
    private function account(string $phone): ?User
    {
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            return $user;
        }

        $name = $this->holders->nameFor($phone);

        if ($name === null) {
            return null;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => null,
            'phone' => $phone,
            'password' => Hash::make(Str::random(48)),
            'status' => AccountStatus::Active,
        ])->save();

        return $user;
    }

    private static function failed(): ValidationException
    {
        return ValidationException::withMessages(['code' => ['The code is incorrect or has expired.']]);
    }
}
