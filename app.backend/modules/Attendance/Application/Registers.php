<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Deliveries;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Domain\Correction;
use Acadly\Modules\Attendance\Journeys\Correction\State;
use Acadly\Modules\Attendance\Notifications\AbsenceTopic;
use Acadly\Modules\Attendance\Notifications\NextContact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** What the Registers screens show (the approved designs). */
final class Registers
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $learners,
        private readonly SchoolDirectory $school,
        private readonly SchoolDays $days,
        private readonly Marks $marks,
        private readonly Reach $reach,
        private readonly Modes $modes,
        private readonly Deliveries $deliveries,
        private readonly Clock $clock,
        private readonly ApprovalRequests $approvals,
    ) {}

    /**
     * Every register the viewer may see on a day.
     *
     * @return array{day: string, today: string, year: string|null, lessons: bool, classes: list<array{id: string, name: string, division: string, campus: string, teacher: string|null, school_day: bool, due: bool, register: array{id: string, submitted_at: string, submitted_by: string}|null, learners: int, counts: array{present: int, absent: int, late: int, excused: int}, corrections: int}>, totals: array{classes: int, due: int, taken: int, not_taken: int, present: int, absent: int, late: int, excused: int, corrections: int}}
     */
    public function day(User $viewer, string $day, ?ScopeRef $in): array
    {
        $year = $this->calendar->currentYear($day);
        $classes = [];

        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $class) {
            $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);

            if ($this->reach->lists($viewer, $class['id']) && ($in === null || $this->within($at, $in))) {
                $classes[] = $class;
            }
        }

        if ($classes === []) {
            $this->access->check($viewer, AttendancePermission::Capture, $in);
        }

        $registers = DB::table('attendance_registers')->whereIn('class_id', array_column($classes, 'id'))->where('day', $day)
            ->where('kind', SubmitRegister::KIND)->get(['id', 'class_id', 'submitted_at', 'submitted_by'])->keyBy('class_id');
        $marks = $this->marks->inForce(array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $registers->all())));
        $pending = array_keys($marks) === [] ? [] : DB::table('attendance_corrections')->whereIn('register_id', array_keys($marks))
            ->where('state', State::Requested->value)->groupBy('class_id')->selectRaw('class_id, count(*) as n')->pluck('n', 'class_id')->all();
        $names = $this->userNames([...array_values(array_filter(array_map(fn (array $c): ?string => $this->calendar->classTeacherId($c['id']), $classes))),
            ...array_values(array_map(fn (object $r): string => Row::text($r, 'submitted_by'), $registers->all()))]);
        $divisions = [];
        $campuses = [];

        foreach ($this->school->divisionViews() as $division) {
            $divisions[$division->id] = $division->name;
        }

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus->name;
        }

        $rows = [];
        $totals = ['classes' => count($classes), 'due' => 0, 'taken' => 0, 'not_taken' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'corrections' => 0];

        foreach ($classes as $class) {
            $register = $registers->get($class['id']);
            $schoolDay = $this->days->isSchoolDay($class['division_id'], $day);
            $statuses = is_object($register) ? array_column($marks[Row::text($register, 'id')] ?? [], 'status') : [];
            $tally = array_count_values($statuses);
            $counts = ['present' => $tally['present'] ?? 0, 'absent' => $tally['absent'] ?? 0, 'late' => $tally['late'] ?? 0, 'excused' => $tally['excused'] ?? 0];

            $teacher = $this->calendar->classTeacherId($class['id']);
            $learners = count($this->learners->learnersIn($class['id'], $day));
            // A register is due on a school day for a class with learners, or when it was taken.
            $due = $schoolDay && ($learners > 0 || is_object($register));
            $open = is_numeric($pending[$class['id']] ?? null) ? (int) $pending[$class['id']] : 0;
            $rows[] = [
                'id' => $class['id'],
                'name' => $class['name'],
                'division' => $divisions[$class['division_id']] ?? '',
                'campus' => $campuses[$class['campus_id']] ?? '',
                'teacher' => $teacher !== null ? ($names[$teacher] ?? null) : null,
                'school_day' => $schoolDay,
                'due' => $due,
                'register' => is_object($register) ? ['id' => Row::text($register, 'id'), 'submitted_at' => Row::text($register, 'submitted_at'),
                    'submitted_by' => $names[Row::text($register, 'submitted_by')] ?? ''] : null,
                'learners' => $learners,
                'counts' => $counts,
                'corrections' => $open,
            ];

            $totals['due'] += $due ? 1 : 0;
            $totals['taken'] += is_object($register) ? 1 : 0;
            $totals['not_taken'] += $due && ! is_object($register) && $day <= $this->today() ? 1 : 0;
            $totals['corrections'] += $open;
            $totals['present'] += $counts['present'];
            $totals['absent'] += $counts['absent'];
            $totals['late'] += $counts['late'];
            $totals['excused'] += $counts['excused'];
        }

        $lessons = array_filter($classes, fn (array $c): bool => $this->modes->takesLessons($c['division_id'])) !== [];

        return ['day' => $day, 'today' => $this->today(), 'year' => $year['name'] ?? null, 'lessons' => $lessons, 'classes' => $rows, 'totals' => $totals];
    }

    /**
     * One class's register on a day: the learners, their last school days, and the marks when submitted.
     *
     * @return array<string, mixed>
     */
    public function register(User $viewer, string $classId, string $day): array
    {
        $class = $this->calendar->classInForce($classId);

        if ($class === null || ! $this->reach->sees($viewer, $classId)) {
            throw (new ModelNotFoundException)->setModel(Correction::class);
        }

        $learners = $this->learners->learnersIn($classId, $day);
        $ids = array_column($learners, 'id');
        $register = DB::table('attendance_registers')->where('class_id', $classId)->where('day', $day)->where('kind', SubmitRegister::KIND)
            ->first(['id', 'submitted_at', 'submitted_by']);
        $registerId = $register !== null ? Row::text($register, 'id') : null;
        $marks = $registerId !== null ? ($this->marks->inForce([$registerId])[$registerId] ?? []) : [];
        $before = $this->days->before($class['division_id'], $day, 4);
        $history = $this->history($ids, $before);
        $corrections = $registerId === null ? [] : $this->correctionsOf($viewer, $registerId);
        $teacher = $this->calendar->classTeacherId($classId);
        $names = $this->userNames(array_values(array_filter([$teacher, $register !== null ? Row::text($register, 'submitted_by') : null])));
        $schoolDay = $this->days->isSchoolDay($class['division_id'], $day);
        $mayTake = $this->reach->takesMorning($viewer, $classId);
        $absences = $registerId === null ? [] : $this->absences($registerId);
        $mayFollow = $mayTake || $this->reach->may($viewer, AttendancePermission::Correct, $classId);

        return [
            'class' => ['id' => $class['id'], 'name' => $class['name']],
            'day' => $day,
            'today' => $this->today(),
            'school_day' => $schoolDay,
            'teacher' => $teacher !== null ? ($names[$teacher] ?? null) : null,
            'register' => $register === null ? null : ['id' => $registerId, 'submitted_at' => Row::text($register, 'submitted_at'),
                'submitted_by' => $names[Row::text($register, 'submitted_by')] ?? ''],
            'days_before' => $before,
            'learners' => array_map(fn (array $l): array => [
                ...$l,
                'history' => array_map(fn (string $d): ?string => $history[$l['id']][$d] ?? null, $before),
                'mark' => $marks[$l['id']] ?? null,
                'correction' => $corrections[$l['id']] ?? null,
                'absence' => isset($absences[$l['id']]) ? [...$absences[$l['id']], 'can_follow_up' => $mayFollow] : null,
            ], $learners),
            'can_take' => $mayTake && $register === null && $schoolDay && $day <= $this->today(),
            'can_request' => $mayTake && $register !== null,
        ];
    }

    /**
     * For each learner first marked absent: who was told and how it stands,
     * the next contact not yet told, and what staff did after.
     *
     * @return array<string, array{told: list<array{to: string, relationship: string, channel: string, state: string, at: string}>, next: string|null, follow_ups: list<array{kind: string, by: string, at: string, note: string|null}>}>
     */
    private function absences(string $registerId): array
    {
        $first = [];

        foreach (DB::table('attendance_marks')->where('register_id', $registerId)->orderBy('received_at')->get(['id', 'learner_id', 'status']) as $m) {
            $first[Row::text($m, 'learner_id')] ??= ['id' => Row::text($m, 'id'), 'status' => Row::text($m, 'status')];
        }

        $first = array_filter($first, fn (array $m): bool => $m['status'] === Mark::Absent->value);

        if ($first === []) {
            return [];
        }

        $about = $this->deliveries->about(AbsenceTopic::SUBJECT, array_column($first, 'id'));
        $steps = DB::table('attendance_follow_ups')->where('register_id', $registerId)->orderBy('done_at')->get();
        $names = $this->userNames(array_values(array_map(fn (object $s): string => Row::text($s, 'done_by'), $steps->all())));
        $out = [];

        foreach ($first as $learner => $mark) {
            $contacts = $this->learners->contactsOf($learner);
            $told = $about[$mark['id']] ?? [];
            $reached = NextContact::told($told);
            $next = null;

            foreach ($contacts as $c) {
                $r = LearnerContacts::recipient($c);

                if (! in_array($r->phone, $reached, true) && ! in_array($r->email, $reached, true)) {
                    $next = $c['name'];

                    break;
                }
            }

            $relation = fn (string $name): string => array_values(array_filter($contacts, fn (array $c): bool => $c['name'] === $name))[0]['relationship'] ?? '';
            $out[$learner] = [
                'told' => array_map(fn (array $d): array => ['to' => $d['to'], 'relationship' => $relation($d['to']), 'channel' => $d['channel'], 'state' => $d['state'], 'at' => $d['at']], $told),
                'next' => $next,
                'follow_ups' => array_values(array_map(fn (object $s): array => ['kind' => Row::text($s, 'kind'), 'by' => $names[Row::text($s, 'done_by')] ?? '',
                    'at' => Row::text($s, 'done_at'), 'note' => Row::maybeText($s, 'note')], array_filter($steps->all(), fn (object $s): bool => Row::text($s, 'learner_id') === $learner))),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $learnerIds
     * @param  list<string>  $days
     * @return array<string, array<string, string>> learner id => day => status
     */
    private function history(array $learnerIds, array $days): array
    {
        if ($learnerIds === [] || $days === []) {
            return [];
        }

        $registers = DB::table('attendance_registers')->whereIn('day', $days)->where('kind', SubmitRegister::KIND)->pluck('day', 'id')->all();
        $out = [];

        foreach ($this->marks->inForce(array_map(strval(...), array_keys($registers))) as $registerId => $byLearner) {
            foreach ($byLearner as $learner => $mark) {
                if (in_array($learner, $learnerIds, true)) {
                    $day = $registers[$registerId] ?? null;

                    if (is_string($day)) {
                        $out[$learner][substr($day, 0, 10)] = $mark['status'];
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{id: string, from: string, to: string, reason: string, state: string, requested_by: string, requested_at: string, can_decide: bool, waiting_for: string|null}> learner id => the latest correction */
    public function correctionsOf(User $viewer, string $registerId): array
    {
        $rows = Correction::query()->where('register_id', $registerId)->orderBy('created_at')->get();
        $names = $this->userNames(array_values(array_map(fn (Correction $c): string => $c->requested_by, $rows->all())));
        $out = [];

        foreach ($rows as $c) {
            $requested = $c->state === State::Requested->value;
            // While the approval waits, its chain decides who is asked now.
            $asked = $requested ? $this->approvals->asks(CorrectionApproval::KEY, $c->id, $viewer) : null;
            $out[$c->learner_id] = [
                'id' => $c->id, 'from' => $c->from_status, 'to' => $c->to_status, 'reason' => $c->reason, 'state' => $c->state,
                'requested_by' => $names[$c->requested_by] ?? '', 'requested_at' => $c->created_at?->toIso8601String() ?? '',
                'can_decide' => $asked !== null ? $asked['asks'] : $requested && $c->requested_by !== $viewer->id
                    && $this->reach->may($viewer, AttendancePermission::Correct, $c->class_id),
                'waiting_for' => $asked !== null && ! $asked['asks'] ? $asked['step'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        $out = [];

        foreach ($ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->get(['id', 'name']) as $row) {
            $out[Row::text($row, 'id')] = Row::text($row, 'name');
        }

        return $out;
    }

    private function within(ScopeRef $at, ScopeRef $in): bool
    {
        foreach ([$at, ...$this->access->ancestors($at)] as $scope) {
            if ($scope->type === $in->type && $scope->id === $in->id) {
                return true;
            }
        }

        return false;
    }

    private function today(): string
    {
        return $this->clock->today();
    }
}
