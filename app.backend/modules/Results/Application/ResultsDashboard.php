<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Illuminate\Support\Facades\DB;

/**
 * Results on the dashboards (D-105, D-100): the share of this semester's
 * course results released. Counts only, each division apart; no student named.
 */
final class ResultsDashboard implements DashboardPanels
{
    public function __construct(private readonly Sheets $sheets) {}

    public function key(): string
    {
        return 'results_college';
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
        $courses = 0;
        $released = 0;
        foreach ($divisions as $d) {
            $period = $this->sheets->current($d);
            if ($period === null) {
                continue;
            }
            $courses += count($this->sheets->offerings($d, $period['id']));
            $released += DB::table('results_sheets')->where('division_id', $d)->where('period_id', $period['id'])->where('sitting', 'main')->where('state', State::Released->value)->count();
        }

        return [Panels::kpi('course_results', $view, 18, 'Course results released', Panels::percent($released, $courses), 'percent',
            $courses === 0 ? 'No courses this semester' : "{$released} of {$courses} courses this semester", 'plain')];
    }

    /** @return list<string> */
    private function divisions(DashboardScope $scope): array
    {
        return array_values(array_filter($scope->sees(ResultsPermission::View), fn (string $d): bool => DB::table('results_sheets')->where('division_id', $d)->exists()));
    }
}
