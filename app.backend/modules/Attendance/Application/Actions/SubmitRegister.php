<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Approvals\Approvals;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\DecidedAbsences;
use Acadly\Modules\Attendance\Application\LateRegisterApproval;
use Acadly\Modules\Attendance\Application\Marks;
use Acadly\Modules\Attendance\Application\MarkSheet;
use Acadly\Modules\Attendance\Application\Reach;
use Acadly\Modules\Attendance\Application\SchoolDays;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Notifications\LearnerAbsent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submits the morning register of a class for a school day (the approved
 * Register screen): every learner in the class that day marked once. The
 * register is submitted once. The same register sent again with the same
 * marks changes nothing; with other marks it is refused, because a change
 * now is a correction (FR-ATT-004). A register for an earlier day is late:
 * when the school has steps for it (D-081) it is asked for, with a reason,
 * and the last yes submits it as the person who took it.
 */
final class SubmitRegister
{
    public const string KIND = 'morning';

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $learners,
        private readonly SchoolDays $days,
        private readonly Marks $marks,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
        private readonly DecidedAbsences $decided,
        private readonly Reach $reach,
        private readonly Approvals $approvals,
        private readonly ApprovalActions $asks,
    ) {}

    /**
     * @param  list<array{learner_id: string, status: string, note: string|null}>  $marks
     * @return string|null the register id; null when a late register was asked for
     */
    public function handle(User $by, string $classId, string $day, array $marks, ?string $reason = null, ?User $approvedBy = null): ?string
    {
        $class = $this->calendar->classInForce($classId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id'] ?? $classId);
        $this->access->check($by, AttendancePermission::Capture, $class !== null ? $at : ScopeRef::institution());

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['The class does not exist or has ended.']]);
        }

        if (! $this->reach->takesMorning($by, $class['id'])) {
            throw new AuthorizationException('The class teacher takes this register. Someone who arranges cover may take it in their place.');
        }

        if ($day > $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['A register cannot be taken for a day still to come.']]);
        }

        if (! $this->days->isSchoolDay($class['division_id'], $day)) {
            throw ValidationException::withMessages(['day' => ['This is not a school day: it is a weekend or outside the terms.']]);
        }

        $given = MarkSheet::check($expected = array_column($this->learners->learnersIn($classId, $day), 'name', 'id'), $marks,
            array_map(fn (array $d): string => $d['note'], $this->decided->on(array_map(strval(...), array_keys($expected)), $day)));

        if ($approvedBy === null && $day < $this->clock->today() && $this->approvals->needed(LateRegisterApproval::KEY)) {
            $this->askLate($by, $classId, $day, $given, trim((string) $reason));

            return null;
        }

        return DB::transaction(function () use ($by, $classId, $day, $given, $at, $approvedBy): string {
            $existing = DB::table('attendance_registers')->where('class_id', $classId)->where('day', $day)->where('kind', self::KIND)->lockForUpdate()->first(['id', 'submitted_at']);

            if ($existing !== null) {
                $id = Row::text($existing, 'id');
                $now = $this->marks->inForce([$id])[$id] ?? [];
                $same = count($now) === count($given) && array_filter($given, fn (array $m, string $learner): bool => ($now[$learner]['status'] ?? null) !== $m['status'], ARRAY_FILTER_USE_BOTH) === [];

                if ($same) {
                    return $id;
                }

                throw ValidationException::withMessages(['register' => ['This register has already been submitted. A change now is a correction.']]);
            }

            $id = Ids::next();
            $now = $this->clock->now();
            DB::table('attendance_registers')->insert(['id' => $id, 'class_id' => $classId, 'day' => $day, 'kind' => self::KIND,
                'submitted_by' => $by->id, 'submitted_at' => $now]);

            foreach ($given as $learner => $mark) {
                $markId = Ids::next();
                DB::table('attendance_marks')->insert(['id' => $markId, 'register_id' => $id, 'learner_id' => $learner,
                    'status' => $mark['status'], 'note' => $mark['note'], 'method' => 'manual', 'captured_by' => $by->id,
                    'captured_at' => $now, 'received_at' => $now]);

                // The family hears of an absence on the day itself; a register for an earlier day tells nobody.
                if ($mark['status'] === Mark::Absent->value && $day === $this->clock->today()) {
                    $this->notifier->send(new LearnerAbsent($markId, $learner, $classId, $day), $by);
                }
            }

            $counts = array_count_values(array_column($given, 'status'));
            $this->audit->record(AttendanceAudit::RegisterSubmitted, actor: $this->audit->actorFor($by), at: $at,
                meta: ['register' => $id, 'day' => $day, ...array_map(intval(...), $counts), 'approved_by' => $approvedBy?->id]);

            return $id;
        });
    }

    /**
     * Keeps a late register as it was taken, and asks the first step. One request per class and day at a time.
     *
     * @param  array<string, array{status: string, note: string|null}>  $given
     */
    private function askLate(User $by, string $classId, string $day, array $given, string $reason): void
    {
        if (DB::table('attendance_registers')->where('class_id', $classId)->where('day', $day)->where('kind', self::KIND)->exists()) {
            throw ValidationException::withMessages(['register' => ['This register has already been submitted. A change now is a correction.']]);
        }

        if (LateRegisterApproval::waiting($classId, $day) !== null) {
            throw ValidationException::withMessages(['register' => ['This late register is already waiting for approval.']]);
        }

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => ['Say why the register is late, in up to 255 characters.']]);
        }

        DB::transaction(function () use ($by, $classId, $day, $given, $reason): void {
            $id = Ids::next();
            $marks = array_map(fn (string $learner, array $m): array => ['learner_id' => $learner, 'status' => $m['status'], 'note' => $m['note']], array_keys($given), $given);
            DB::table('attendance_late_registers')->insert(['id' => $id, 'class_id' => $classId, 'day' => $day, 'marks' => json_encode($marks, JSON_THROW_ON_ERROR),
                'reason' => $reason, 'requested_by' => $by->id, 'requested_at' => $this->clock->now()]);
            $this->asks->open(LateRegisterApproval::KEY, $id);
        });
    }
}
