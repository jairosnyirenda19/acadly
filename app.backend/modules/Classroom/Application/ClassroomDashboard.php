<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Classroom on the dashboards (D-104, D-100): the share of work due in the
 * last 30 days that was handed in. Counts only, each division apart; no
 * student named.
 */
final class ClassroomDashboard implements DashboardPanels
{
    public function __construct(
        private readonly ClassroomViews $views,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'classroom';
    }

    public function views(DashboardScope $scope): array
    {
        return $this->divisions($scope) === [] ? [] : ['overview'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        $divisions = $this->divisions($scope);
        if ($view !== 'overview' || $divisions === []) {
            return [];
        }
        $now = $this->clock->now();
        $from = CarbonImmutable::parse($scope->day)->subDays(30);
        $handed = 0;
        $due = 0;
        foreach (DB::table('classroom_tasks')->whereIn('division_id', $divisions)->whereNull('removed_at')->whereBetween('due_at', [$from, $now])->get() as $t) {
            $c = $this->views->counts($t);
            $handed += $c['handed_in'] + $c['late'];
            $due += $c['registered'];
        }

        return [Panels::kpi('coursework', $view, 17, 'Coursework handed in', Panels::percent($handed, $due), 'percent',
            $due === 0 ? 'No work was due in the last 30 days' : "{$handed} of {$due} due in the last 30 days", 'plain')];
    }

    /** @return list<string> */
    private function divisions(DashboardScope $scope): array
    {
        return array_values(array_filter($scope->sees(ClassroomPermission::View), fn (string $d): bool => DB::table('classroom_tasks')->where('division_id', $d)->exists()));
    }
}
