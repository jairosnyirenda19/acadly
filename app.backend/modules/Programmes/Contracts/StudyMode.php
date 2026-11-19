<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/** How a student studies a programme (D-098). A part-time student may take less than the lowest load. */
enum StudyMode: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full time',
            self::PartTime => 'Part time',
        };
    }
}
