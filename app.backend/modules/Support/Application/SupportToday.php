<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;

/** Support's part of Today (D-100): the reviews of the plans the person leads, drawn by SupportDashboard. */
final class SupportToday implements TodayItems
{
    public function __construct(private readonly SupportDashboard $dashboard) {}

    public function key(): string
    {
        return 'support';
    }

    public function today(DashboardScope $scope): array
    {
        return $this->dashboard->today($scope);
    }
}
