<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Lectures\Contracts\LectureAttendance;
use Illuminate\Support\Facades\DB;

/** The Lectures contract Results reads for the attendance bar (D-105). */
final class AttendanceReader implements LectureAttendance
{
    public function rates(string $offeringId): array
    {
        $sessions = array_values(array_map(fn (object $s): string => Row::text($s, 'id'),
            DB::table('lecture_sessions')->where('offering_id', $offeringId)->whereNotNull('closed_at')->get(['id'])->all()));
        // As on the student's own record: the closed lectures they hold a mark for count as held.
        $out = [];
        foreach (Marks::latest($sessions) as $m) {
            $learner = Row::text($m, 'learner_id');
            $out[$learner] ??= ['held' => 0, 'attended' => 0];
            $out[$learner]['held']++;
            if (in_array(Row::text($m, 'mark'), ['present', 'late'], true)) {
                $out[$learner]['attended']++;
            }
        }

        return $out;
    }
}
