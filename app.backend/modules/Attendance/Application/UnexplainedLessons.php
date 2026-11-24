<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Contracts\MissedLessons;
use Illuminate\Support\Facades\DB;

/** Lessons missed with no accepted reason: the lesson marks of Absent in force (D-111). */
final class UnexplainedLessons implements MissedLessons
{
    public function __construct(private readonly Marks $marks) {}

    public function byDay(array $learnerIds, string $from, string $to): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $registers = DB::table('attendance_registers as r')->join('attendance_marks as m', 'm.register_id', '=', 'r.id')
            ->where('r.kind', SubmitLessonRegister::KIND)->whereBetween('r.day', [$from, $to])->whereIn('m.learner_id', $learnerIds)
            ->distinct()->get(['r.id', 'r.day']);
        $dayOf = [];

        foreach ($registers as $r) {
            $dayOf[Row::text($r, 'id')] = Row::text($r, 'day');
        }

        $out = [];

        foreach ($this->marks->inForce(array_keys($dayOf)) as $registerId => $byLearner) {
            foreach ($byLearner as $learner => $mark) {
                if ($mark['status'] === Mark::Absent->value && in_array($learner, $learnerIds, true)) {
                    $day = $dayOf[$registerId];
                    $out[$learner][$day] = ($out[$learner][$day] ?? 0) + 1;
                }
            }
        }

        return $out;
    }
}
