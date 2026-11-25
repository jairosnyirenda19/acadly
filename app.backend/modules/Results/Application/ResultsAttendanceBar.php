<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Modules\Lectures\Contracts\AttendanceBar;

/** The attendance bar each division sets in Results settings (D-105); none where Results is off (D-113). */
final class ResultsAttendanceBar implements AttendanceBar
{
    public function __construct(private readonly ModuleActivation $activation) {}

    public function percent(string $divisionId): int
    {
        if (! $this->activation->isActive('results', ScopeRef::of(ScopeType::Division, $divisionId))) {
            return 0;
        }

        return Scales::settings($divisionId)['bar_percent'];
    }
}
