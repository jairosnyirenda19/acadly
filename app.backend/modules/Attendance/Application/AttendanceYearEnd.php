<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Journeys\Correction\State;
use Illuminate\Support\Facades\DB;

/**
 * Attendance at the year-end run (D-088): each learner's share of mornings
 * present this year, and the register corrections still waiting, which
 * stop the commit.
 */
final class AttendanceYearEnd implements YearEndCheck
{
    public function column(string $yearId, array $learnerIds): ?array
    {
        if ($learnerIds === []) {
            return null;
        }

        $year = DB::table('academic_years')->where('id', $yearId)->first(['starts_on', 'ends_on']);

        if ($year === null) {
            return null;
        }

        // The mark in force of each morning register is its latest.
        $marks = DB::query()->fromSub(DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('r.kind', SubmitRegister::KIND)->whereBetween('r.day', [Row::text($year, 'starts_on'), Row::text($year, 'ends_on')])
            ->whereIn('m.learner_id', $learnerIds)
            ->selectRaw('distinct on (m.register_id, m.learner_id) m.learner_id, m.status')
            ->orderBy('m.register_id')->orderBy('m.learner_id')->orderByDesc('m.received_at')->orderByDesc('m.id'), 'k')
            ->selectRaw('learner_id, count(*) as marked, sum(case when status = ? then 0 else 1 end) as here', [Mark::Absent->value])
            ->groupBy('learner_id')->get();
        $values = [];

        foreach ($marks as $m) {
            $marked = Row::int($m, 'marked');

            if ($marked > 0) {
                $values[Row::text($m, 'learner_id')] = (int) round(Row::int($m, 'here') / $marked * 100).'%';
            }
        }

        return ['label' => 'Present', 'values' => $values];
    }

    public function problems(string $yearId, array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $waiting = DB::table('attendance_corrections')->whereIn('class_id', $classIds)->where('state', State::Requested->value)->count();

        return $waiting === 0 ? [] : [$waiting === 1 ? 'One register correction is waiting for approval.' : "{$waiting} register corrections are waiting for approval."];
    }
}
