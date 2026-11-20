<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\Actions\SendSignInCode;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\StudentAccountDirectory;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Student accounts (D-101). The registry keeps the phone a student's codes go
 * to, sends a set-up code, and suspends or restores sign-in. The student sets
 * up the account with the code and a password of their own; nobody else ever
 * sees or sets it. The account opens only the student portal.
 */
final class StudentAccountActions
{
    public const string PURPOSE = 'student_set_up';

    /** The end of a student account's internal address. */
    public const string INTERNAL = '@students.invalid';

    private const int CODE_MINUTES = 10;

    private const int MAX_TRIES = 5;

    public function __construct(
        private readonly Place $place,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly SchoolProfile $school,
    ) {}

    /** The phone a student's codes go to. */
    public function setPhone(User $by, string $divisionId, string $learnerId, string $written): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Accounts);
        $this->student($divisionId, $learnerId);
        $phone = Phone::international($written) ?? throw ValidationException::withMessages(['phone' => ['Give a mobile number, for example 0991 234 567.']]);

        DB::transaction(function () use ($by, $divisionId, $learnerId, $phone): void {
            $this->row($by, $divisionId, $learnerId);
            DB::table('programme_student_accounts')->where('learner_id', $learnerId)->update(['phone' => $phone]);
            $this->audit->record(ProgrammesAudit::StudentPhoneSet, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['learner' => $learnerId]);
        });
    }

    /**
     * Sends set-up codes to students: each gets an account if they have none, and a code by text
     * message to the phone on record. A student with no phone is left out.
     *
     * @param  list<string>  $learnerIds
     * @return array{sent: int, no_phone: int}
     */
    public function sendCodes(User $by, string $divisionId, array $learnerIds): array
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Accounts);
        $out = ['sent' => 0, 'no_phone' => 0];

        foreach (array_values(array_unique($learnerIds)) as $learnerId) {
            $name = $this->student($divisionId, $learnerId);
            DB::transaction(function () use ($by, $divisionId, $learnerId, $name, &$out): void {
                $row = $this->row($by, $divisionId, $learnerId);
                $phone = Row::maybeText($row, 'phone');
                if ($phone === null) {
                    $out['no_phone']++;

                    return;
                }
                $userId = Row::maybeText($row, 'user_id') ?? $this->makeAccount($learnerId, $name);
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $id = Ids::next();
                $now = $this->clock->now();
                DB::table('sign_in_codes')->where('phone', $phone)->where('purpose', self::PURPOSE)->whereNull('used_at')->update(['used_at' => $now]);
                DB::table('sign_in_codes')->insert(['id' => $id, 'phone' => $phone, 'code_hash' => SendSignInCode::hash($phone, $code), 'attempts' => 0, 'ip' => '',
                    'created_at' => $now, 'expires_at' => $now->addMinutes(self::CODE_MINUTES), 'purpose' => self::PURPOSE]);
                DB::table('programme_student_accounts')->where('learner_id', $learnerId)->update(['user_id' => $userId, 'code_sent_at' => $now]);
                $number = StudentAccountDirectory::numberOf($learnerId);
                SendSignInCodeText::dispatch($id, $phone, "{$this->school->shortName()} student portal. Your set-up code is {$code}. Student number {$number}. It works for 10 minutes. Do not share it.");
                $this->audit->record(ProgrammesAudit::StudentCodeSent, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['learner' => $learnerId]);
                $out['sent']++;
            });
        }

        return $out;
    }

    /** Stops a student's sign-in at once and ends every session. */
    public function suspend(User $by, string $divisionId, string $learnerId, string $reason): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Accounts);
        $this->student($divisionId, $learnerId);
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);
        $row = DB::table('programme_student_accounts')->where('learner_id', $learnerId)->first(['user_id', 'suspended_at']);
        $userId = $row === null ? null : Row::maybeText($row, 'user_id');
        if ($userId === null || Row::maybeText($row, 'suspended_at') !== null) {
            throw ValidationException::withMessages(['learner_id' => ['The student has no account in use.']]);
        }

        DB::transaction(function () use ($by, $divisionId, $learnerId, $userId, $reason): void {
            DB::table('programme_student_accounts')->where('learner_id', $learnerId)->update(['suspended_at' => $this->clock->now(), 'suspended_by' => $by->id, 'suspended_reason' => $reason]);
            DB::table('users')->where('id', $userId)->update(['status' => AccountStatus::Disabled->value, 'remember_token' => null]);
            self::endSessions($userId);
            $this->audit->record(ProgrammesAudit::StudentSuspended, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), reason: $reason, meta: ['learner' => $learnerId]);
        });
    }

    public function restore(User $by, string $divisionId, string $learnerId, string $reason): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Accounts);
        $this->student($divisionId, $learnerId);
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);
        $userId = DB::table('programme_student_accounts')->where('learner_id', $learnerId)->whereNotNull('suspended_at')->value('user_id');
        if (! is_string($userId)) {
            throw ValidationException::withMessages(['learner_id' => ['The student\'s sign-in is not suspended.']]);
        }

        DB::transaction(function () use ($by, $divisionId, $learnerId, $userId, $reason): void {
            DB::table('programme_student_accounts')->where('learner_id', $learnerId)->update(['suspended_at' => null, 'suspended_by' => null, 'suspended_reason' => null]);
            DB::table('users')->where('id', $userId)->update(['status' => AccountStatus::Active->value]);
            $this->audit->record(ProgrammesAudit::StudentRestored, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), reason: $reason, meta: ['learner' => $learnerId]);
        });
    }

    /**
     * The student sets up the account: the student number, the code sent to their phone,
     * and a password of their own. One message for every failure.
     */
    public function setUp(string $number, string $code, string $password, string $ip): User
    {
        $key = 'student-set-up:'.$ip;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ThrottleRequestsException('Too many attempts.');
        }
        $wrong = fn (): ValidationException => ValidationException::withMessages(['code' => ['The student number or the code is not right, or the code has expired.']]);
        $learner = StudentAccountDirectory::learnerForNumber($number);
        $row = $learner === null ? null : DB::table('programme_student_accounts')->where('learner_id', $learner)->whereNull('suspended_at')->first(['user_id', 'phone', 'division_id']);
        $phone = $row === null ? null : Row::maybeText($row, 'phone');
        $userId = $row === null ? null : Row::maybeText($row, 'user_id');

        if ($phone === null || $userId === null) {
            RateLimiter::hit($key, 600);

            throw $wrong();
        }
        $this->checkPassword($password, $number, $userId);

        return DB::transaction(function () use ($phone, $code, $userId, $password, $learner, $row, $key, $wrong): User {
            $now = $this->clock->now();
            $live = DB::table('sign_in_codes')->where('phone', $phone)->where('purpose', self::PURPOSE)->whereNull('used_at')->where('expires_at', '>', $now)
                ->orderByDesc('created_at')->lockForUpdate()->first(['id', 'code_hash', 'attempts']);
            if ($live === null || Row::int($live, 'attempts') >= self::MAX_TRIES || ! hash_equals(Row::text($live, 'code_hash'), SendSignInCode::hash($phone, trim($code)))) {
                if ($live !== null) {
                    DB::table('sign_in_codes')->where('id', Row::text($live, 'id'))->increment('attempts');
                }
                RateLimiter::hit($key, 600);

                throw $wrong();
            }
            DB::table('sign_in_codes')->where('id', Row::text($live, 'id'))->update(['used_at' => $now]);
            $user = User::query()->findOrFail($userId);
            $user->forceFill(['password' => Hash::make($password), 'status' => AccountStatus::Active->value])->save();
            DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => $now]);
            $this->audit->record(ProgrammesAudit::StudentAccountSetUp, actor: $this->audit->actorFor($user), at: ScopeRef::of(ScopeType::Division, Row::text($row, 'division_id')), meta: ['learner' => (string) $learner]);
            RateLimiter::clear($key);

            return $user;
        });
    }

    /** A password the student may keep: 12 or more characters, not their number, not their name. */
    private function checkPassword(string $password, string $number, string $userId): void
    {
        $stored = DB::table('users')->where('id', $userId)->value('name');
        $name = mb_strtolower(is_string($stored) ? $stored : '');
        $plain = mb_strtolower($password);
        $parts = array_filter(preg_split('/\s+/', $name) ?: [], fn (string $p): bool => mb_strlen($p) >= 3);
        $named = array_filter($parts, fn (string $p): bool => str_contains($plain, $p)) !== [];

        if (mb_strlen($password) < 12 || str_contains($plain, mb_strtolower(trim($number))) || $named) {
            throw ValidationException::withMessages(['password' => ['Use 12 or more characters. Not your student number, not your name.']]);
        }
    }

    /** The student's name, when the learner is a student of the division. */
    private function student(string $divisionId, string $learnerId): string
    {
        $row = DB::table('programme_students as s')->join('learners as l', 'l.id', '=', 's.learner_id')->where('s.division_id', $divisionId)->where('s.learner_id', $learnerId)
            ->first(['l.first_name', 'l.surname']) ?? throw new NotFoundHttpException;

        return Row::text($row, 'first_name').' '.Row::text($row, 'surname');
    }

    /** The account row of a student, made when first needed. */
    private function row(User $by, string $divisionId, string $learnerId): object
    {
        $row = DB::table('programme_student_accounts')->where('learner_id', $learnerId)->first();
        if ($row === null) {
            DB::table('programme_student_accounts')->insert(['learner_id' => $learnerId, 'division_id' => $divisionId, 'created_by' => $by->id, 'created_at' => $this->clock->now()]);
            $row = DB::table('programme_student_accounts')->where('learner_id', $learnerId)->first();
        }

        return $row ?? throw new NotFoundHttpException;
    }

    /**
     * A student account: a password nobody knows until the student sets one. Every account needs an
     * address; a student's is an internal one that receives nothing and is never shown.
     */
    private function makeAccount(string $learnerId, string $name): string
    {
        $user = User::query()->create(['name' => $name, 'email' => $learnerId.self::INTERNAL, 'password' => Hash::make(Str::random(48)), 'status' => AccountStatus::Active->value]);

        return $user->id;
    }

    /** Ends every session and token of an account. */
    public static function endSessions(string $userId): void
    {
        if (DB::getSchemaBuilder()->hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }
        DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
    }
}
