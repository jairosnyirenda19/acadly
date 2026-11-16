<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Modules\Attendance\Contracts\AttendanceMode;
use Acadly\Modules\Attendance\Contracts\AttendanceSlot;

/** How each level takes registers: the value its profile binds to `AttendanceSlot::Mode`. */
final class Modes
{
    /** @var array<string, AttendanceMode> */
    private array $known = [];

    public function __construct(private readonly SchoolDirectory $school) {}

    public function of(string $divisionId): AttendanceMode
    {
        if (! isset($this->known[$divisionId])) {
            $mode = $this->school->division($divisionId)?->profile->binding(AttendanceSlot::Mode);
            $this->known[$divisionId] = $mode instanceof AttendanceMode ? $mode : AttendanceMode::Daily;
        }

        return $this->known[$divisionId];
    }

    public function takesLessons(string $divisionId): bool
    {
        return $this->of($divisionId)->takesLessons();
    }
}
