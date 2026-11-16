<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

/** A learner's mark in a register (FR-ATT-002). */
enum Mark: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Late => 'Late',
            self::Excused => 'Excused',
        };
    }
}
