<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

use Acadly\Kernel\Access\Permission;

/** The attendance module's permissions. */
enum AttendancePermission: string implements Permission
{
    case Capture = 'attendance.capture';
    case Correct = 'attendance.correct';
    case AllClasses = 'attendance.all_classes';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Capture => 'Take the register',
            self::Correct => 'Approve a register correction',
            self::AllClasses => 'See every register',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Capture => 'Assigned classes only. Assignment follows the timetable.',
            self::Correct => "Not available for one's own request.",
            self::AllClasses => 'SEES EVERY REGISTER in the division. Grant to the academic office only.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::AllClasses;
    }
}
