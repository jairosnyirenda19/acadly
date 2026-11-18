<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\PhoneHolders;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sends a six-digit sign-in code by SMS to a phone number the school keeps
 * (D-082).
 *
 * - The answer is the same whether or not the number is known, so the
 *   screen never confirms who is a contact of the school.
 * - Three codes per number in ten minutes; twenty per address in an hour.
 * - The code expires after ten minutes. Only a keyed hash is stored.
 */
final class SendSignInCode
{
    public const int EXPIRES_MINUTES = 10;

    public function __construct(
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly PhoneHolders $holders,
        private readonly SchoolProfile $school,
    ) {}

    public function handle(string $written, string $ip, string $language = 'en'): void
    {
        $phone = Phone::international($written)
            ?? throw ValidationException::withMessages(['phone' => ['Enter a phone number, such as 0991 234 567.']]);

        foreach (['sign-in-code:'.$phone => [3, 600], 'sign-in-code-ip:'.$ip => [20, 3600]] as $key => [$most, $seconds]) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                throw new ThrottleRequestsException('Too many codes asked for.');
            }

            RateLimiter::hit($key, $seconds);
        }

        $account = User::query()->where('phone', $phone)->first();
        $known = $account !== null ? $account->isActive() : $this->holders->nameFor($phone) !== null;

        if (! $known) {
            $this->audit->record(KernelAudit::SignInCodeRefused, subject: $account, outcome: Outcome::Refused, refusal: 'phone_not_known',
                actor: Actor::anonymous(), personal: ['phone' => $phone]);

            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $this->clock->now();
        $id = Ids::next();

        DB::transaction(function () use ($id, $phone, $code, $now, $ip, $account, $language): void {
            DB::table('sign_in_codes')->insert([
                'id' => $id,
                'phone' => $phone,
                'code_hash' => self::hash($phone, $code),
                'ip' => $ip,
                'created_at' => $now,
                'expires_at' => $now->addMinutes(self::EXPIRES_MINUTES),
            ]);

            SendSignInCodeText::dispatch($id, $phone, $this->text($code, $language));

            $this->audit->record(KernelAudit::SignInCodeSent, subject: $account,
                actor: $account !== null ? $this->audit->actorFor($account) : Actor::anonymous(), personal: ['phone' => $phone]);
        });
    }

    /** The keyed hash of a code for one number. */
    public static function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, config()->string('app.key'));
    }

    private function text(string $code, string $language): string
    {
        return self::words($this->school->name(), $code, $language, false);
    }

    /**
     * The text message with a code: to sign in, or to add the number to an
     * account (D-087). The Chichewa wording is a draft until the school
     * checks it (D-082).
     */
    public static function words(string $school, string $code, string $language, bool $toAdd): string
    {
        $minutes = self::EXPIRES_MINUTES;

        if ($toAdd) {
            return $language === 'ny'
                ? "{$school}: nambala yowonjezera nambalayi ku akaunti yanu ndi {$code}. Igwira ntchito kwa mphindi {$minutes}. Musapereke nambalayi kwa wina aliyense."
                : "{$school}: your code to add this number to your account is {$code}. It expires in {$minutes} minutes. Do not share this code.";
        }

        return $language === 'ny'
            ? "{$school}: nambala yanu yolowera ndi {$code}. Igwira ntchito kwa mphindi {$minutes}. Musapereke nambalayi kwa wina aliyense."
            : "{$school}: your sign-in code is {$code}. It expires in {$minutes} minutes. Do not share this code.";
    }
}
