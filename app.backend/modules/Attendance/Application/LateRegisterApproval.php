<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Approvals\OptionalApproval;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A morning register taken after its day, in the Approvals catalogue (D-081).
 * It is kept as asked and counts for nothing until the last yes, which
 * submits it as the person who took it. Families are not told: the day has
 * passed. No steps until the school sets some: late registers submit at once.
 */
final class LateRegisterApproval implements OptionalApproval
{
    public const string KEY = 'attendance.late_register';

    public function __construct(private readonly AcademicCalendar $calendar) {}

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
        return 'Late register';
    }

    public function description(): string
    {
        return 'A morning register taken after the day it was due. With steps, it counts for nothing until the last yes.';
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
        return ['has_absent' => 'a learner is marked absent'];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('attendance_late_registers')->where('id', $subjectId)->first() : null;

        if ($row === null) {
            return null;
        }

        $classId = Row::text($row, 'class_id');
        $class = $this->calendar->classNames([$classId])[$classId]['name'] ?? '';
        $day = substr(Row::text($row, 'day'), 0, 10);
        $marks = self::marks(Row::text($row, 'marks'));
        $counts = array_count_values(array_column($marks, 'status'));
        $teacher = $this->calendar->classTeacherId($classId);

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: ScopeRef::of(ScopeType::ClassGroup, $classId),
            title: 'Morning register · '.CarbonImmutable::parse($day)->format('D j M'),
            where: $class.' · '.count($marks).' marked: '.($counts[Mark::Present->value] ?? 0).' present, '.($counts[Mark::Absent->value] ?? 0).' absent',
            reason: Row::text($row, 'reason'),
            link: '/registers/'.$classId.'?day='.$day,
            related: ['class_teacher' => $teacher !== null ? [$teacher] : []],
            facts: ['has_absent' => ($counts[Mark::Absent->value] ?? 0) > 0],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $row = DB::table('attendance_late_registers')->where('id', $subjectId)->first();
        $taker = $row !== null ? User::query()->find(Row::text($row, 'requested_by')) : null;

        if ($approved && $row !== null && $taker instanceof User) {
            app(SubmitRegister::class)->handle($taker, Row::text($row, 'class_id'), substr(Row::text($row, 'day'), 0, 10),
                self::marks(Row::text($row, 'marks')), Row::text($row, 'reason'), approvedBy: $by);
        }
    }

    /**
     * A late register still waiting for a class and day, with who it waits for.
     *
     * @return array{id: string, reason: string, marks: array<string, string>, asked_by: string, waiting_for: list<string>}|null
     */
    public static function waiting(string $classId, string $day): ?array
    {
        $row = DB::table('attendance_late_registers as x')->join('approval_requests as r', 'r.subject_id', '=', 'x.id')
            ->where('r.topic_key', self::KEY)->where('r.state', 'waiting')->where('x.class_id', $classId)->where('x.day', $day)->first(['x.id', 'x.reason', 'x.marks']);
        $pending = $row !== null ? app(ApprovalRequests::class)->pending(self::KEY, Row::text($row, 'id')) : null;

        if ($row === null || $pending === null) {
            return null;
        }

        return ['id' => $pending['id'], 'reason' => Row::text($row, 'reason'), 'marks' => array_column(self::marks(Row::text($row, 'marks')), 'status', 'learner_id'),
            'asked_by' => $pending['asked_by'], 'waiting_for' => $pending['waiting_for']];
    }

    /** @return list<array{learner_id: string, status: string, note: string|null}> */
    public static function marks(string $json): array
    {
        $out = [];

        $list = json_decode($json, true);

        foreach (is_array($list) ? $list : [] as $m) {
            if (is_array($m) && is_string($m['learner_id'] ?? null) && is_string($m['status'] ?? null)) {
                $out[] = ['learner_id' => $m['learner_id'], 'status' => $m['status'], 'note' => is_string($m['note'] ?? null) ? $m['note'] : null];
            }
        }

        return $out;
    }
}
