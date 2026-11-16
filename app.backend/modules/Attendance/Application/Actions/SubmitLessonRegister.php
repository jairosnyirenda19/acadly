<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Lessons;
use Acadly\Modules\Attendance\Application\Marks;
use Acadly\Modules\Attendance\Application\MarkSheet;
use Acadly\Modules\Attendance\Application\Modes;
use Acadly\Modules\Attendance\Application\SchoolDays;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Notifications\LessonMissed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Submits the register of one lesson on the timetable (the approved lesson
 * register): every learner in the class that day marked once. Anyone who may
 * take the class's registers may take it; when that is not the timetabled
 * teacher, it is cover. A lesson register is submitted once; a change after
 * is a correction, as for the morning register. A register for today tells
 * the family of a learner here this morning and absent now, when the school
 * has that notice on.
 */
final class SubmitLessonRegister
{
    public const string KIND = 'lesson';

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Timetable $timetable,
        private readonly EnrolmentDirectory $learners,
        private readonly SchoolDays $days,
        private readonly Modes $modes,
        private readonly Marks $marks,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<array{learner_id: string, status: string, note: string|null}>  $marks
     * @return string the register id
     */
    public function handle(User $by, string $slotId, string $day, array $marks): string
    {
        $lesson = $this->timetable->lesson($slotId, $day);
        $class = $lesson !== null ? $this->calendar->classInForce($lesson['class_id']) : null;

        if ($lesson === null || $class === null) {
            $this->access->check($by, AttendancePermission::Capture);

            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);
        $this->access->check($by, AttendancePermission::Capture, $at);

        if (! $this->modes->takesLessons($class['division_id'])) {
            throw ValidationException::withMessages(['slot_id' => ['This level takes a morning register only.']]);
        }

        if (! $this->days->isSchoolDay($class['division_id'], $day)) {
            throw ValidationException::withMessages(['day' => ['This is not a school day: it is a weekend or outside the terms.']]);
        }

        if (! Lessons::opened($lesson, $this->clock)) {
            throw ValidationException::withMessages(['day' => ['A lesson register can be taken from 15 minutes before the lesson starts.']]);
        }

        $given = MarkSheet::check(array_column($this->learners->learnersIn($class['id'], $day), 'name', 'id'), $marks);

        return DB::transaction(function () use ($by, $lesson, $class, $day, $given, $at): string {
            $existing = DB::table('attendance_registers')->where('slot_id', $lesson['slot_id'])->where('day', $day)->where('kind', self::KIND)->lockForUpdate()->first(['id']);

            if ($existing !== null) {
                $id = Row::text($existing, 'id');
                $now = $this->marks->inForce([$id])[$id] ?? [];
                $same = count($now) === count($given) && array_filter($given, fn (array $m, string $learner): bool => ($now[$learner]['status'] ?? null) !== $m['status'], ARRAY_FILTER_USE_BOTH) === [];

                return $same ? $id : throw ValidationException::withMessages(['register' => ['This register has already been submitted. A change now is a correction.']]);
            }

            $id = Ids::next();
            $now = $this->clock->now();
            $cover = $lesson['teacher_id'] !== $by->id;
            DB::table('attendance_registers')->insert(['id' => $id, 'class_id' => $class['id'], 'day' => $day, 'kind' => self::KIND,
                'submitted_by' => $by->id, 'submitted_at' => $now, 'slot_id' => $lesson['slot_id'], 'subject_id' => $lesson['subject_id'],
                'period' => $lesson['period'], 'period_name' => $lesson['name'], 'starts' => $lesson['starts'], 'ends' => $lesson['ends'],
                'teacher_id' => $lesson['teacher_id'], 'cover' => $cover]);

            $morningId = DB::table('attendance_registers')->where('class_id', $class['id'])->where('day', $day)->where('kind', SubmitRegister::KIND)->value('id');
            $morning = is_string($morningId) ? ($this->marks->inForce([$morningId])[$morningId] ?? []) : [];
            $today = $day === $this->clock->today();

            foreach ($given as $learner => $mark) {
                $markId = Ids::next();
                DB::table('attendance_marks')->insert(['id' => $markId, 'register_id' => $id, 'learner_id' => $learner,
                    'status' => $mark['status'], 'note' => $mark['note'], 'method' => 'manual', 'captured_by' => $by->id,
                    'captured_at' => $now, 'received_at' => $now]);

                if ($today && $mark['status'] === Mark::Absent->value && in_array($morning[$learner]['status'] ?? null, [Mark::Present->value, Mark::Late->value], true)) {
                    $this->notifier->send(new LessonMissed($markId, $learner, $class['id'], $day, $lesson['subject'], $lesson['starts']), $by);
                }
            }

            $counts = array_count_values(array_column($given, 'status'));
            $this->audit->record(AttendanceAudit::LessonRegisterSubmitted, actor: $this->audit->actorFor($by), at: $at,
                meta: ['register' => $id, 'day' => $day, 'period' => $lesson['period'], 'cover' => $cover ? 1 : 0, ...array_map(intval(...), $counts)]);

            return $id;
        });
    }
}
