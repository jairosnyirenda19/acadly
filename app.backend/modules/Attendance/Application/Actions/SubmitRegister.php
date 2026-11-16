<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Marks;
use Acadly\Modules\Attendance\Application\SchoolDays;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submits the morning register of a class for a school day (the approved
 * Register screen): every learner in the class that day marked once. The
 * register is submitted once. The same register sent again with the same
 * marks changes nothing; with other marks it is refused, because a change
 * now is a correction (FR-ATT-004).
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
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<array{learner_id: string, status: string, note: string|null}>  $marks
     * @return string the register id
     */
    public function handle(User $by, string $classId, string $day, array $marks): string
    {
        $class = $this->calendar->classInForce($classId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id'] ?? $classId);
        $this->access->check($by, AttendancePermission::Capture, $class !== null ? $at : ScopeRef::institution());

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['The class does not exist or has ended.']]);
        }

        if ($day > $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['A register cannot be taken for a day still to come.']]);
        }

        if (! $this->days->isSchoolDay($class['division_id'], $day)) {
            throw ValidationException::withMessages(['day' => ['This is not a school day: it is a weekend or outside the terms.']]);
        }

        $expected = array_column($this->learners->learnersIn($classId, $day), 'name', 'id');
        $given = [];

        foreach ($marks as $i => $mark) {
            if (! isset($expected[$mark['learner_id']])) {
                throw ValidationException::withMessages(["marks.{$i}" => ['This learner is not in the class on this day.']]);
            }

            if (isset($given[$mark['learner_id']])) {
                throw ValidationException::withMessages(["marks.{$i}" => ['This learner is marked twice.']]);
            }

            if (Mark::tryFrom($mark['status']) === null) {
                throw ValidationException::withMessages(["marks.{$i}" => ['Choose present, absent, late or excused.']]);
            }

            $note = trim((string) $mark['note']);

            if (mb_strlen($note) > 255) {
                throw ValidationException::withMessages(["marks.{$i}" => ['A reason can be up to 255 characters.']]);
            }

            $given[$mark['learner_id']] = ['status' => $mark['status'], 'note' => $note !== '' ? $note : null];
        }

        $missing = count(array_diff_key($expected, $given));

        if ($missing > 0) {
            throw ValidationException::withMessages(['marks' => [($missing === 1 ? '1 learner is' : "{$missing} learners are").' not marked. Mark every learner, then submit.']]);
        }

        return DB::transaction(function () use ($by, $classId, $day, $given, $at): string {
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
                DB::table('attendance_marks')->insert(['id' => Ids::next(), 'register_id' => $id, 'learner_id' => $learner,
                    'status' => $mark['status'], 'note' => $mark['note'], 'method' => 'manual', 'captured_by' => $by->id,
                    'captured_at' => $now, 'received_at' => $now]);
            }

            $counts = array_count_values(array_column($given, 'status'));
            $this->audit->record(AttendanceAudit::RegisterSubmitted, actor: $this->audit->actorFor($by), at: $at,
                meta: ['register' => $id, 'day' => $day, ...array_map(intval(...), $counts)]);

            return $id;
        });
    }
}
