<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

/** How a level takes registers: bound by its profile to `AttendanceSlot::Mode`. */
enum AttendanceMode: string
{
    /** One morning register a school day for each class. */
    case Daily = 'daily';

    /** The morning register, and a register for each lesson on the class timetable. */
    case DailyAndLessons = 'daily_and_lessons';

    public function takesLessons(): bool
    {
        return $this === self::DailyAndLessons;
    }
}
