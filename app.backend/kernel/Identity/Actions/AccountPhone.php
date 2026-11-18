<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\FamilyAccounts;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\PhoneHolders;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The phone number on a member of staff's own account (D-087): added and
 * confirmed with a code sent by SMS, so the parent portal opens the same
 * account; removed, so it no longer does.
 *
 * - Only a number the school keeps for a learner's contact, or that already
 *   signs in to the parent portal, can be added.
 * - A parent account already on the number is closed, and its family
 *   records move to this account.
 * - Removing the number gives the family records back to an account of
 *   their own, which the number signs in to.
 */
final class AccountPhone
{
    public const string PURPOSE = 'link:';

    public function __construct(
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly PhoneHolders $holders,
        private readonly FamilyAccounts $families,
        private readonly SchoolProfile $school,
    ) {}

    public function sendCode(User $by, string $written, string $ip, string $language = 'en'): void
    {
        $phone = $this->number($by, $written);

        foreach (['sign-in-code:'.$phone => [3, 600], 'sign-in-code-ip:'.$ip => [20, 3600]] as $key => [$most, $seconds]) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                throw new ThrottleRequestsException('Too many codes asked for.');
            }

            RateLimiter::hit($key, $seconds);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $this->clock->now();
        $id = Ids::next();
        $school = $this->school->name();

        DB::transaction(function () use ($id, $phone, $code, $now, $ip, $by, $school, $language): void {
            DB::table('sign_in_codes')->insert(['id' => $id, 'phone' => $phone, 'code_hash' => SendSignInCode::hash($phone, $code), 'ip' => $ip,
                'created_at' => $now, 'expires_at' => $now->addMinutes(SendSignInCode::EXPIRES_MINUTES), 'purpose' => self::PURPOSE.$by->id]);
            SendSignInCodeText::dispatch($id, $phone, SendSignInCode::words($school, $code, $language, true));
            $this->audit->record(KernelAudit::SignInCodeSent, subject: $by, actor: $this->audit->actorFor($by), personal: ['phone' => $phone]);
        });
    }

    public function confirm(User $by, string $written, string $code): void
    {
        $phone = $this->number($by, $written);
        $code = (string) preg_replace('/\D/', '', $code);
        $now = $this->clock->now();

        DB::transaction(function () use ($by, $phone, $code, $now): void {
            $row = DB::table('sign_in_codes')->where('phone', $phone)->where('purpose', self::PURPOSE.$by->id)->whereNull('used_at')->where('expires_at', '>', $now)
                ->orderByDesc('created_at')->lockForUpdate()->first(['id', 'code_hash', 'attempts']);

            if ($row === null || Row::int($row, 'attempts') >= SignInWithCode::MAX_TRIES || ! hash_equals(Row::text($row, 'code_hash'), SendSignInCode::hash($phone, $code))) {
                if ($row !== null) {
                    DB::table('sign_in_codes')->where('id', $row->id)->increment('attempts');
                }

                throw ValidationException::withMessages(['code' => ['The code is incorrect or has expired.']]);
            }

            DB::table('sign_in_codes')->where('id', $row->id)->update(['used_at' => $now]);
            $earlier = User::query()->where('phone', $phone)->whereKeyNot($by->id)->lockForUpdate()->first();

            if ($earlier !== null) {
                // The parent account closes and gives up the number; a closed account needs no way to reach it.
                $earlier->forceFill(['phone' => null, 'status' => AccountStatus::Disabled, 'remember_token' => Str::random(60)])->save();
            }

            $by->forceFill(['phone' => $phone])->save();

            if ($earlier !== null) {
                $this->families->moveAccount($earlier->id, $by->id);
            }

            $this->audit->record(KernelAudit::AccountPhoneAdded, subject: $by, actor: $this->audit->actorFor($by), personal: ['phone' => $phone],
                meta: ['closed' => $earlier?->id]);
        });
    }

    public function remove(User $by): void
    {
        $phone = $by->phone;

        if ($phone === null) {
            return;
        }

        if ($by->email === null) {
            throw ValidationException::withMessages(['phone' => ['This account signs in with this number only.']]);
        }

        DB::transaction(function () use ($by, $phone): void {
            $by->forceFill(['phone' => null])->save();

            if ($this->families->holds($by->id)) {
                $own = new User;
                $own->forceFill(['name' => $this->holders->nameFor($phone) ?? $by->name, 'email' => null, 'phone' => $phone,
                    'password' => Hash::make(Str::random(48)), 'status' => AccountStatus::Active])->save();
                $this->families->moveAccount($by->id, $own->id);
            }

            $this->audit->record(KernelAudit::AccountPhoneRemoved, subject: $by, actor: $this->audit->actorFor($by), personal: ['phone' => $phone]);
        });
    }

    /** The number to add, in the international form, once it is one this account may take. */
    private function number(User $by, string $written): string
    {
        $phone = Phone::international($written)
            ?? throw ValidationException::withMessages(['phone' => ['Enter a phone number, such as 0991 234 567.']]);

        if ($by->email === null) {
            throw ValidationException::withMessages(['phone' => ['This account already signs in with its number.']]);
        }

        if ($by->phone !== null && $by->phone !== $phone) {
            throw ValidationException::withMessages(['phone' => ['Remove the number on this account first.']]);
        }

        $earlier = User::query()->where('phone', $phone)->whereKeyNot($by->id)->first();

        if ($earlier !== null && $earlier->email !== null) {
            throw ValidationException::withMessages(['phone' => ['This number is on another staff account. Ask the school office.']]);
        }

        if ($earlier === null && $this->holders->nameFor($phone) === null) {
            throw ValidationException::withMessages(['phone' => ['The school does not have this number for a learner. Ask the school office to add it to your child\'s contacts.']]);
        }

        return $phone;
    }
}
