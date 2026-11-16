<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

use Acadly\Kernel\Blueprint\Profile\BindingSlot;
use BackedEnum;

/** The choices Attendance leaves to each level's profile (02 §2.4). */
enum AttendanceSlot: string implements BindingSlot
{
    /** How registers are taken. */
    case Mode = 'attendance.mode';

    public function key(): string
    {
        return $this->value;
    }

    public function accepts(): string
    {
        return match ($this) {
            self::Mode => AttendanceMode::class,
        };
    }

    public function fallback(): BackedEnum
    {
        return match ($this) {
            self::Mode => AttendanceMode::Daily,
        };
    }
}
