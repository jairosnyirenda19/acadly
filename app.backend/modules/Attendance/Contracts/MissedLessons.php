<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

/**
 * Missed lessons with no accepted reason (D-111): lesson-register marks of
 * Absent still in force. An Excused mark, a suspension included, never counts.
 */
interface MissedLessons
{
    /**
     * Each learner's missed lessons on each day between two days.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, array<string, int>> learner id => day => lessons missed
     */
    public function byDay(array $learnerIds, string $from, string $to): array;
}
