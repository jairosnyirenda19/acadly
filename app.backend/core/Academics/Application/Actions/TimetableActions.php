<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Application\Timetables;
use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writes the timetable (the approved Timetable screens): the bell times of a
 * level at a campus, and what each class is taught in each period. The school
 * types it; nothing is generated. A change to a period applies from today:
 * the row in force ends yesterday and a new one starts, so a lesson already
 * taught keeps what it was.
 */
final class TimetableActions
{
    public const int MAX_PERIODS = 16;

    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
        private readonly Timetables $timetables,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Replaces the bell plans of a level at a campus.
     *
     * @param  list<array{name?: mixed, weekdays?: mixed, periods?: mixed}>  $plans
     */
    public function saveBells(User $by, string $divisionId, string $campusId, array $plans): void
    {
        $offering = $this->school->offeringId($divisionId, $campusId);
        $this->access->check($by, AcademicsPermission::Manage, $offering !== null ? ScopeRef::of(ScopeType::Offering, $offering) : ScopeRef::institution());

        if ($offering === null) {
            throw ValidationException::withMessages(['campus_id' => ['This level is not taught at this campus.']]);
        }

        $clean = [];
        $taken = [];

        if ($plans === [] || count($plans) > 7) {
            throw ValidationException::withMessages(['plans' => ['Set bell times for at least one group of days, and at most seven.']]);
        }

        foreach ($plans as $i => $plan) {
            $name = trim(is_string($plan['name'] ?? null) ? $plan['name'] : '');
            $weekdays = is_array($plan['weekdays'] ?? null) ? array_values(array_unique(array_map(intval(...), array_filter($plan['weekdays'], is_numeric(...))))) : [];
            sort($weekdays);

            if ($name === '' || mb_strlen($name) > 60) {
                throw ValidationException::withMessages(["plans.{$i}.name" => ['Name the days, such as Monday to Thursday. Up to 60 characters.']]);
            }

            if ($weekdays === [] || array_filter($weekdays, fn (int $d): bool => $d < 1 || $d > 7) !== []) {
                throw ValidationException::withMessages(["plans.{$i}.weekdays" => ['Choose the days these bell times are for.']]);
            }

            foreach ($weekdays as $d) {
                if (isset($taken[$d])) {
                    throw ValidationException::withMessages(["plans.{$i}.weekdays" => ['A day can have one set of bell times only.']]);
                }

                $taken[$d] = true;
            }

            $clean[] = ['name' => $name, 'weekdays' => $weekdays, 'periods' => $this->periods($i, $plan['periods'] ?? null)];
        }

        DB::transaction(function () use ($by, $divisionId, $campusId, $offering, $clean): void {
            $now = $this->clock->now();
            DB::table('academic_bell_plans')->where('division_id', $divisionId)->where('campus_id', $campusId)->delete();

            foreach ($clean as $position => $plan) {
                DB::table('academic_bell_plans')->insert(['id' => Ids::next(), 'division_id' => $divisionId, 'campus_id' => $campusId,
                    'name' => $plan['name'], 'weekdays' => json_encode($plan['weekdays']), 'periods' => json_encode($plan['periods']),
                    'position' => $position, 'updated_by' => $by->id, 'created_at' => $now, 'updated_at' => $now]);
            }

            $this->audit->record(AcademicsAudit::BellsSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Offering, $offering),
                meta: ['plans' => count($clean), 'periods' => array_sum(array_map(fn (array $p): int => count($p['periods']), $clean))]);
        });

        $this->timetables->forget();
    }

    /** Sets what a class is taught in one period of one day of the week, or makes it a free period (no subject). */
    public function setPeriod(User $by, string $classId, int $weekday, int $period, ?string $subjectId, ?string $userId): void
    {
        $class = Str::isUuid($classId) ? $this->calendar->classInForce($classId) : null;
        $this->access->check($by, AcademicsPermission::Manage, $class !== null ? ScopeRef::of(ScopeType::ClassGroup, $class['id']) : ScopeRef::institution());

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['The class does not exist or has ended.']]);
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);

        $plan = null;

        foreach ($this->timetables->plansOf($class['division_id'], $class['campus_id']) as $p) {
            if (in_array($weekday, $p['weekdays'], true)) {
                $plan = $p;
            }
        }

        $lessons = $plan === null ? 0 : count(array_filter($plan['periods'], fn (array $p): bool => $p['kind'] === 'lesson'));

        if ($period < 1 || $period > $lessons) {
            throw ValidationException::withMessages(['period' => ['The bell times of this day have no such period.']]);
        }

        if ($subjectId === null && $userId !== null) {
            throw ValidationException::withMessages(['subject_id' => ['Choose a subject, or make it a free period.']]);
        }

        if ($subjectId !== null) {
            $subject = DB::table('academic_subjects')->where('id', $subjectId)->whereNull('retired_at')->first(['division_id', 'levels']);
            $levels = $subject !== null ? json_decode(Row::text($subject, 'levels'), true) : null;

            if ($subject === null || Row::text($subject, 'division_id') !== $class['division_id'] || ! is_array($levels) || ! in_array($class['level_code'], $levels, true)) {
                throw ValidationException::withMessages(['subject_id' => ['This class does not take this subject.']]);
            }
        }

        if ($userId !== null && ! in_array($userId, $this->access->holderIds(AcademicsPermission::Teach, $at), true)) {
            throw ValidationException::withMessages(['user_id' => ['This person cannot teach in this class. Their roles there do not include Teach classes.']]);
        }

        $today = $this->clock->today();

        if ($userId !== null) {
            $clash = DB::table('academic_timetable as t')->join('academic_classes as c', 'c.id', '=', 't.class_id')
                ->where('t.user_id', $userId)->where('t.weekday', $weekday)->where('t.period', $period)->where('t.class_id', '!=', $classId)
                ->whereNull('t.ends_on')->whereNull('c.ended_at')->value('c.name');

            if (is_string($clash)) {
                $who = DB::table('users')->where('id', $userId)->value('name');
                throw ValidationException::withMessages(['user_id' => [(is_string($who) ? $who : 'This teacher').' teaches '.$clash.' at this time. Choose another period or another teacher.']]);
            }
        }

        DB::transaction(function () use ($by, $classId, $weekday, $period, $subjectId, $userId, $today, $at): void {
            $current = DB::table('academic_timetable')->where('class_id', $classId)->where('weekday', $weekday)->where('period', $period)
                ->whereNull('ends_on')->lockForUpdate()->first(['id', 'subject_id', 'user_id', 'starts_on']);

            if ($current !== null && Row::text($current, 'subject_id') === $subjectId && Row::maybeText($current, 'user_id') === $userId) {
                return;
            }

            if ($current !== null) {
                // The row in force ends yesterday. One set today never applied to any day, and ends the day before it began.
                $started = substr(Row::text($current, 'starts_on'), 0, 10);
                $end = CarbonImmutable::parse($started >= $today ? $started : $today)->subDay()->toDateString();
                DB::table('academic_timetable')->where('id', Row::text($current, 'id'))->update(['ends_on' => $end]);
            }

            if ($subjectId !== null) {
                DB::table('academic_timetable')->insert(['id' => Ids::next(), 'class_id' => $classId, 'weekday' => $weekday, 'period' => $period,
                    'subject_id' => $subjectId, 'user_id' => $userId, 'starts_on' => $today, 'ends_on' => null, 'set_by' => $by->id, 'set_at' => $this->clock->now()]);
            }

            $this->audit->record(AcademicsAudit::TimetableSet, actor: $this->audit->actorFor($by), at: $at,
                meta: ['weekday' => $weekday, 'period' => $period, 'free' => $subjectId === null]);
        });
    }

    /** @return list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}> */
    private function periods(int $plan, mixed $given): array
    {
        $field = "plans.{$plan}.periods";

        if (! is_array($given) || $given === [] || count($given) > self::MAX_PERIODS + 8) {
            throw ValidationException::withMessages([$field => ['Add the periods and breaks of the day.']]);
        }

        $out = [];
        $last = null;
        $lessons = 0;

        foreach (array_values($given) as $j => $p) {
            $p = is_array($p) ? $p : [];
            $name = trim(is_string($p['name'] ?? null) ? $p['name'] : '');
            $starts = is_string($p['starts'] ?? null) ? substr($p['starts'], 0, 5) : '';
            $ends = is_string($p['ends'] ?? null) ? substr($p['ends'], 0, 5) : '';
            $kind = ($p['kind'] ?? null) === 'break' ? 'break' : 'lesson';

            if ($name === '' || mb_strlen($name) > 40) {
                throw ValidationException::withMessages(["{$field}.{$j}" => ['Name each period and break. Up to 40 characters.']]);
            }

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $starts) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ends) !== 1 || $ends <= $starts) {
                throw ValidationException::withMessages(["{$field}.{$j}" => ["{$name}: the end must be after the start."]]);
            }

            if ($last !== null && $starts < $last) {
                throw ValidationException::withMessages(["{$field}.{$j}" => ["{$name} starts before the one above it ends."]]);
            }

            $last = $ends;
            $lessons += $kind === 'lesson' ? 1 : 0;
            $out[] = ['name' => $name, 'starts' => $starts, 'ends' => $ends, 'kind' => $kind];
        }

        if ($lessons === 0 || $lessons > self::MAX_PERIODS) {
            throw ValidationException::withMessages([$field => ['A day has between 1 and '.self::MAX_PERIODS.' lesson periods.']]);
        }

        return $out;
    }
}
