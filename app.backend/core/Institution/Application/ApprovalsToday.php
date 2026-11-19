<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Approvals\ApprovalRequests;

/** The requests waiting for the person's answer, on Today (D-100), kept to the place worked in. */
final class ApprovalsToday implements TodayItems
{
    public function __construct(private readonly ApprovalRequests $approvals) {}

    public function key(): string
    {
        return 'institution';
    }

    public function today(DashboardScope $scope): array
    {
        $waiting = $this->approvals->waitingFor($scope->viewer);

        if ($waiting === []) {
            return [];
        }

        $first = $waiting[0];

        return ['items' => [['key' => 'approvals', 'order' => 13, 'title' => 'Approvals', 'sub' => $first['title'].(count($waiting) > 1 ? ' and '.(count($waiting) - 1).' more' : ''),
            'count' => (string) count($waiting), 'tone' => 'attention', 'href' => '/approvals']]];
    }
}
