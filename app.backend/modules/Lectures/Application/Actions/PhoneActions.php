<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Lectures\Application\Passkeys;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The student's attendance phone (D-102): one a student, held by a passkey.
 * The student sets it up once; the registry resets it when the phone is lost
 * or changed, with a reason, and the student sets up the new one.
 */
final class PhoneActions
{
    private const int CHALLENGE_MINUTES = 5;

    public function __construct(
        private readonly CourseTeaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly Where $where,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * What the phone needs to make the passkey: a fresh challenge, kept for five minutes.
     *
     * @return array{challenge: string, rp_id: string, user_handle: string, name: string}
     */
    public function begin(User $student): array
    {
        $me = $this->teaching->studentOf($student->id) ?? throw new NotFoundHttpException;
        if ($this->inUse($me['learner_id'])) {
            throw ValidationException::withMessages(['phone' => ['You have an attendance phone. Ask the registry to reset it to use another.']]);
        }
        $challenge = Passkeys::encode(random_bytes(32));
        Cache::put(self::key($student->id), $challenge, now()->addMinutes(self::CHALLENGE_MINUTES));

        return ['challenge' => $challenge, 'rp_id' => Passkeys::rpId(), 'user_handle' => Passkeys::encode($student->id), 'name' => $student->name];
    }

    public function set(User $student, string $clientDataJson, string $attestationObject): void
    {
        $me = $this->teaching->studentOf($student->id) ?? throw new NotFoundHttpException;
        $challenge = Cache::pull(self::key($student->id));
        if (! is_string($challenge)) {
            throw ValidationException::withMessages(['phone' => ['That took too long. Start again.']]);
        }
        $key = Passkeys::register($clientDataJson, $attestationObject, $challenge);
        if ($this->inUse($me['learner_id'])) {
            throw ValidationException::withMessages(['phone' => ['You have an attendance phone. Ask the registry to reset it to use another.']]);
        }
        DB::transaction(function () use ($student, $me, $key): void {
            DB::table('lecture_phones')->insert(['id' => Ids::next(), 'division_id' => $me['division_id'], 'learner_id' => $me['learner_id'], 'user_id' => $student->id,
                'credential_id' => $key['credential_id'], 'public_key' => $key['public_key'], 'sign_count' => $key['sign_count'], 'created_at' => $this->clock->now()]);
            $this->audit->record(LecturesAudit::PhoneSet, actor: $this->audit->actorFor($student), at: ScopeRef::of(ScopeType::Division, $me['division_id']), meta: ['learner' => $me['learner_id']]);
        });
    }

    /** Checks the person may reset this student's phone; gives the student's division. */
    public function mayReset(User $by, string $learnerId): string
    {
        $division = $this->learners->placeOf($learnerId)['division_id'] ?? throw new NotFoundHttpException;
        $this->where->division($by, $division, LecturesPermission::Phones);

        return $division;
    }

    public function reset(User $by, string $learnerId, string $reason): void
    {
        $division = $this->mayReset($by, $learnerId);
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);
        if (! $this->inUse($learnerId)) {
            throw ValidationException::withMessages(['reason' => ['This student has no attendance phone.']]);
        }
        DB::transaction(function () use ($by, $learnerId, $reason, $division): void {
            DB::table('lecture_phones')->where('learner_id', $learnerId)->whereNull('reset_at')->update(['reset_at' => $this->clock->now(), 'reset_by' => $by->id, 'reset_reason' => $reason]);
            $this->audit->record(LecturesAudit::PhoneReset, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $division), reason: $reason, meta: ['learner' => $learnerId]);
        });
    }

    private function inUse(string $learnerId): bool
    {
        return DB::table('lecture_phones')->where('learner_id', $learnerId)->whereNull('reset_at')->exists();
    }

    private static function key(string $userId): string
    {
        return 'lectures.phone.'.$userId;
    }
}
