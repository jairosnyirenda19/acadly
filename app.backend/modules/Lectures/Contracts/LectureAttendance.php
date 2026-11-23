<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Contracts;

/**
 * What Results reads from the lecture timetable (D-105): for each student of
 * a course, the closed lectures held and those they attended (present or late).
 */
interface LectureAttendance
{
    /**
     * @return array<string, array{held: int, attended: int}>
     */
    public function rates(string $offeringId): array;
}
