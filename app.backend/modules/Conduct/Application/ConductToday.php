<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;

/** Conduct's part of Today (D-100): the open serious incidents the person leads, drawn by ConductDashboard. */
final class ConductToday implements TodayItems
{
    public function __construct(private readonly ConductDashboard $dashboard) {}

    public function key(): string
    {
        return 'conduct';
    }

    public function today(DashboardScope $scope): array
    {
        return $this->dashboard->today($scope);
    }
}
