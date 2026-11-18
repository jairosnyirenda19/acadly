<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Identity\Totp;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in (D-089): after the password, a six-digit code from an
 * authenticator app, or one of ten recovery codes. A person turns it on and
 * off for themselves; the office can turn it off for someone who lost the
 * phone. A code is never accepted twice.
 */
final class TwoStep
{
    public const string PENDING = 'acadly.two_step_pending';

    public const int RECOVERY_CODES = 10;

    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly SchoolProfile $school,
    ) {}

    /**
     * Starts turning it on: a new key, kept until a code confirms it.
     *
     * @return array{key: string, address: string}
     */
    public function begin(User $who): array
    {
        if ($who->two_step_on_at !== null) {
            throw ValidationException::withMessages(['two_step' => ['Two-step sign-in is already on.']]);
        }

        $secret = Totp::newSecret();
        $who->forceFill(['two_step_secret' => $secret])->save();

        return ['key' => trim(chunk_split($secret, 4, ' ')), 'address' => Totp::address($secret, (string) ($who->email ?? $who->name), $this->school->name())];
    }

    /**
     * Turns it on once a code from the app matches; answers the ten recovery codes, shown once.
     *
     * @return list<string>
     */
    public function confirm(User $who, string $code): array
    {
        $secret = $who->two_step_secret;

        if ($who->two_step_on_at !== null || ! is_string($secret)) {
            throw ValidationException::withMessages(['code' => ['Start again: add the key to your app first.']]);
        }

        $step = Totp::match($secret, $code, $this->clock->now()->getTimestamp());

        if ($step === null) {
            throw ValidationException::withMessages(['code' => ['The code is not the one the app shows now.']]);
        }

        return DB::transaction(function () use ($who, $step): array {
            $now = $this->clock->now();
            $who->forceFill(['two_step_on_at' => $now, 'two_step_last_step' => $step])->save();
            DB::table('two_step_recovery_codes')->where('user_id', $who->id)->whereNull('retired_at')->update(['retired_at' => $now]);
            $codes = [];

            for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
                $code = strtoupper(Str::random(4).'-'.Str::random(4));
                $codes[] = $code;
                DB::table('two_step_recovery_codes')->insert(['id' => Ids::next(), 'user_id' => $who->id, 'code_hash' => self::hash($who, $code), 'created_at' => $now]);
            }

            $this->audit->record(KernelAudit::TwoStepOn, subject: $who, actor: $this->audit->actorFor($who));

            return $codes;
        });
    }

    /** Turns it off: by the person, with their password, or by whoever may give them roles. */
    public function turnOff(User $by, User $who, ?string $password): void
    {
        if ($by->is($who)) {
            if (! is_string($password) || ! Hash::check($password, $who->password)) {
                throw ValidationException::withMessages(['password' => ['The password is not correct.']]);
            }
        } elseif (! $this->access->reaches($by, AccessPermission::AssignRoles, $who)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($by, $who): void {
            $who->forceFill(['two_step_secret' => null, 'two_step_on_at' => null, 'two_step_last_step' => null])->save();
            DB::table('two_step_recovery_codes')->where('user_id', $who->id)->whereNull('retired_at')->update(['retired_at' => $this->clock->now()]);
            $this->audit->record(KernelAudit::TwoStepOff, subject: $who, actor: $this->audit->actorFor($by), meta: ['by_office' => ! $by->is($who)]);
        });
    }

    /** The second step of a sign-in: the password was right a moment ago; a code finishes it. */
    public function signIn(Request $request, string $code): User
    {
        $pending = $request->session()->get(self::PENDING);
        $id = is_array($pending) && is_string($pending['user'] ?? null) ? $pending['user'] : null;
        $until = is_array($pending) && is_int($pending['until'] ?? null) ? $pending['until'] : 0;
        $now = $this->clock->now();

        if ($id === null || $until < $now->getTimestamp()) {
            $request->session()->forget(self::PENDING);

            throw ValidationException::withMessages(['code' => ['Sign in again with your email address and password.']]);
        }

        $key = 'two-step:'.$id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ThrottleRequestsException('Too many codes tried.');
        }

        $who = User::query()->find($id);
        $secret = $who?->two_step_secret;

        if (! $who instanceof User || ! $who->isActive() || ! is_string($secret)) {
            throw ValidationException::withMessages(['code' => ['Sign in again with your email address and password.']]);
        }

        $step = Totp::match($secret, $code, $now->getTimestamp(), $who->two_step_last_step);
        $recovery = $step === null ? DB::table('two_step_recovery_codes')->where('user_id', $who->id)->whereNull('used_at')->whereNull('retired_at')
            ->where('code_hash', self::hash($who, strtoupper(trim($code))))->value('id') : null;

        if ($step === null && ! is_string($recovery)) {
            RateLimiter::hit($key, 600);
            $this->audit->record(KernelAudit::SignInFailed, subject: $who, outcome: Outcome::Failed, actor: Actor::anonymous(), meta: ['by' => 'two_step']);

            throw ValidationException::withMessages(['code' => ['The code is not correct.']]);
        }

        DB::transaction(function () use ($who, $step, $recovery, $now): void {
            if ($step !== null) {
                $who->forceFill(['two_step_last_step' => $step]);
            } else {
                DB::table('two_step_recovery_codes')->where('id', $recovery)->update(['used_at' => $now]);
            }

            $who->forceFill(['last_signed_in_at' => $now])->save();
        });

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING);
        Auth::guard('web')->login($who);
        $this->audit->record(KernelAudit::SignedIn, subject: $who, actor: $this->audit->actorFor($who), meta: ['by' => $step !== null ? 'two_step' : 'recovery_code']);

        return $who;
    }

    private static function hash(User $who, string $code): string
    {
        return hash_hmac('sha256', $who->id.'|'.$code, config()->string('app.key'));
    }
}
