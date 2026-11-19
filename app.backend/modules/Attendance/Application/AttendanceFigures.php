<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Support\Facades\DB;

/**
 * Today's attendance on the Divisions start screen (D-096): present out of
 * marked in the morning registers, and how many registers are taken, for
 * those who may see every register of the division.
 */
final class AttendanceFigures implements DivisionFigures
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDays $days,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function figures(User $viewer, array $divisionIds, string $day): array
    {
        $seen = array_values(array_filter($divisionIds, fn (string $id): bool => $this->access->allows($viewer, AttendancePermission::AllClasses, ScopeRef::of(ScopeType::Division, $id))));
        $year = $this->calendar->currentYear($day);

        if ($seen === [] || $year === null) {
            return [];
        }

        $classes = [];

        foreach ($this->calendar->classesInYear($year['id']) as $c) {
            if (in_array($c['division_id'], $seen, true)) {
                $classes[$c['id']] = $c['division_id'];
            }
        }

        $taken = [];

        foreach (DB::table('attendance_registers')->where('day', $day)->where('kind', SubmitRegister::KIND)->whereIn('class_id', array_keys($classes) ?: ['00000000-0000-0000-0000-000000000000'])->get(['class_id']) as $r) {
            $division = $classes[Row::text($r, 'class_id')] ?? null;

            if ($division !== null) {
                $taken[$division] = ($taken[$division] ?? 0) + 1;
            }
        }

        // The mark in force is the newest of each learner's mark in the register.
        $marks = DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('r.day', $day)->where('r.kind', SubmitRegister::KIND)->whereIn('r.class_id', array_keys($classes) ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderBy('m.learner_id')->orderByDesc('m.received_at')->orderByDesc('m.id')
            ->selectRaw('distinct on (m.learner_id) m.learner_id, m.status, r.class_id')->get();
        $present = [];
        $marked = [];

        foreach ($marks as $m) {
            $division = $classes[Row::text($m, 'class_id')] ?? null;

            if ($division !== null) {
                $marked[$division] = ($marked[$division] ?? 0) + 1;
                $present[$division] = ($present[$division] ?? 0) + (Row::text($m, 'status') === Mark::Absent->value ? 0 : 1);
            }
        }

        $out = [];

        foreach ($seen as $id) {
            $expected = count(array_filter($classes, fn (string $d): bool => $d === $id));
            $done = $taken[$id] ?? 0;
            $figures = ['present' => $present[$id] ?? 0, 'marked' => $marked[$id] ?? 0];

            if ($expected > 0 && $this->days->isSchoolDay($id, $day)) {
                $figures['state'] = $done >= $expected
                    ? ['tone' => 'done', 'text' => "{$done} of {$expected} registers"]
                    : ['tone' => 'attention', 'text' => ($expected - $done).' registers open'];
            }

            $out[$id] = $figures;
        }

        return $out;
    }
}
