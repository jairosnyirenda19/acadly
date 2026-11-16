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
    case Cover = 'attendance.cover';

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
            self::Cover => 'Arrange cover',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Capture => 'Take the morning register and lesson registers of a class where the role is given, and ask for a correction after one is submitted.',
            self::Correct => 'Approve or decline a correction to a register. Never one the person asked for.',
            self::AllClasses => 'See the registers of every class where the role is given, without taking them.',
            self::Cover => 'Mark staff away for a day and ask someone to cover their lessons, where the role is given.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::AllClasses;
    }
}
