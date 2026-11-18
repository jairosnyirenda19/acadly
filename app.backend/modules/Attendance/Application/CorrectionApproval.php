<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Domain\Correction;
use Acadly\Modules\Attendance\Journeys\Correction\OutcomeId;
use Acadly\Modules\Attendance\Journeys\Correction\StepId;
use Illuminate\Support\Facades\DB;

/**
 * Register corrections in the Approvals catalogue. The chain decides who is
 * asked; when it has decided, the journey's own decision step is performed.
 */
final class CorrectionApproval implements ApprovalTopic
{
    public const string KEY = 'attendance.correction';

    public function __construct(
        private readonly JourneyEngine $engine,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $learners,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function area(): string
    {
        return 'Attendance';
    }

    public function title(): string
    {
        return 'Register correction';
    }

    public function description(): string
    {
        return 'A change to a mark in a submitted register.';
    }

    public function permission(): Permission
    {
        return AttendancePermission::Correct;
    }

    public function relations(): array
    {
        return ['class_teacher' => 'The class teacher'];
    }

    public function facts(): array
    {
        return ['to_present' => 'the change is from absent to present or late'];
    }

    /**
     * The facts a step's condition reads, for a change from one mark to another.
     *
     * @return array<string, bool>
     */
    public static function factsOf(string $from, string $to): array
    {
        return ['to_present' => $from === Mark::Absent->value && in_array($to, [Mark::Present->value, Mark::Late->value], true)];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $c = Correction::query()->find($subjectId);

        if (! $c instanceof Correction) {
            return null;
        }

        $register = DB::table('attendance_registers')->where('id', $c->register_id)->first(['day', 'kind', 'slot_id', 'period_name']);
        $day = $register !== null ? substr(Row::text($register, 'day'), 0, 10) : '';
        $lesson = $register !== null && Row::text($register, 'kind') === SubmitLessonRegister::KIND ? $register : null;
        $class = $this->calendar->classNames([$c->class_id])[$c->class_id]['name'] ?? '';
        $name = $this->learners->names([$c->learner_id])[$c->learner_id] ?? '';
        $teacher = $this->calendar->classTeacherId($c->class_id);
        $word = fn (string $s): string => mb_strtolower(Mark::tryFrom($s)?->label() ?? $s);

        return new ApprovalCase(
            requesterId: $c->requested_by,
            at: ScopeRef::of(ScopeType::ClassGroup, $c->class_id),
            title: "{$name}: {$word($c->from_status)} to {$word($c->to_status)}",
            where: trim($class.' · '.($lesson !== null ? Row::text($lesson, 'period_name').' · ' : '').date('j M', strtotime($day) ?: 0)),
            reason: $c->reason,
            link: $lesson !== null ? '/registers/lesson/'.Row::text($lesson, 'slot_id').'?day='.$day : '/registers/'.$c->class_id.'?day='.$day,
            related: ['class_teacher' => $teacher !== null ? [$teacher] : []],
            facts: self::factsOf($c->from_status, $c->to_status),
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $c = Correction::query()->findOrFail($subjectId);
        $this->engine->perform($c, $by, StepId::Decide, $approved ? OutcomeId::Approved : OutcomeId::Declined, $reason !== null ? ['reason' => $reason] : []);
    }
}
