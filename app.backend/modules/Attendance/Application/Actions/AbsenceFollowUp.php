<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Reach;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Notifications\LearnerAbsentNextContact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * When the family of an absent learner was not reached (the approved
 * design): tell the next contact, or record that the family was called.
 * Both are kept in the history of the absence.
 */
final class AbsenceFollowUp
{
    public const string TOLD_NEXT = 'told_next';

    public const string CALLED = 'called';

    public function __construct(
        private readonly Reach $reach,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function tellNext(User $by, string $registerId, string $learnerId): void
    {
        [$register, $mark] = $this->absence($by, $registerId, $learnerId);

        DB::transaction(function () use ($by, $register, $mark, $learnerId): void {
            $round = DB::table('attendance_follow_ups')->where('mark_id', $mark)->where('kind', self::TOLD_NEXT)->count() + 1;
            $sent = $this->notifier->send(new LearnerAbsentNextContact($mark, $learnerId, Row::text($register, 'class_id'), substr(Row::text($register, 'day'), 0, 10), $round), $by);

            if ($sent === null) {
                throw ValidationException::withMessages(['learner_id' => ['The next contact has already been told.']]);
            }

            $this->write($by, $register, $mark, $learnerId, self::TOLD_NEXT, null);
        });
    }

    public function called(User $by, string $registerId, string $learnerId, ?string $note): void
    {
        [$register, $mark] = $this->absence($by, $registerId, $learnerId);
        $note = trim((string) $note);

        if (mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => ['A note can be up to 255 characters.']]);
        }

        DB::transaction(fn () => $this->write($by, $register, $mark, $learnerId, self::CALLED, $note !== '' ? $note : null));
    }

    /** @return array{stdClass, string} the register and the first mark of the absence */
    private function absence(User $by, string $registerId, string $learnerId): array
    {
        $register = DB::table('attendance_registers')->where('id', $registerId)->first(['id', 'class_id', 'day']);

        if ($register === null || ! $this->reach->sees($by, Row::text($register, 'class_id'))) {
            throw new NotFoundHttpException;
        }

        if (! $this->reach->may($by, AttendancePermission::Capture, Row::text($register, 'class_id')) && ! $this->reach->may($by, AttendancePermission::Correct, Row::text($register, 'class_id'))) {
            throw new AuthorizationException;
        }

        $first = DB::table('attendance_marks')->where('register_id', $registerId)->where('learner_id', $learnerId)->orderBy('received_at')->first(['id', 'status']);

        if ($first === null || Row::text($first, 'status') !== Mark::Absent->value) {
            throw ValidationException::withMessages(['learner_id' => ['This learner was not marked absent on this register.']]);
        }

        return [$register, Row::text($first, 'id')];
    }

    private function write(User $by, stdClass $register, string $mark, string $learnerId, string $kind, ?string $note): void
    {
        DB::table('attendance_follow_ups')->insert(['id' => Ids::next(), 'mark_id' => $mark, 'register_id' => Row::text($register, 'id'), 'learner_id' => $learnerId,
            'kind' => $kind, 'note' => $note, 'done_by' => $by->id, 'done_at' => $this->clock->now()]);
        $this->audit->record(AttendanceAudit::AbsenceFollowedUp, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::ClassGroup, Row::text($register, 'class_id')),
            meta: ['register' => Row::text($register, 'id'), 'learner' => $learnerId, 'kind' => $kind]);
    }
}
