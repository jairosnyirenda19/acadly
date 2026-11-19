<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Illuminate\Support\Facades\DB;

/**
 * Pick-up's part of Today (D-100): for the person whose room or class it is,
 * the children still to hand over today, and those going home with someone
 * named for today only, who must be checked at the gate.
 */
final class PickupToday implements TodayItems
{
    public function __construct(private readonly AcademicCalendar $calendar) {}

    public function key(): string
    {
        return 'saferelease';
    }

    public function today(DashboardScope $scope): array
    {
        $year = $this->calendar->currentYear($scope->day);
        $items = [];

        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $c) {
            if (! in_array($c['division_id'], $scope->ids(), true) || $this->calendar->classTeacherId($c['id']) !== $scope->viewer->id) {
                continue;
            }
            $children = DB::table('placements')->where('class_id', $c['id'])->whereNull('ends_on')->pluck('learner_id')->all();
            if ($children === []) {
                continue;
            }
            $released = DB::table('pickups')->where('class_id', $c['id'])->where('day', $scope->day)->where('state', 'released')->count();
            $forToday = DB::table('release_collectors')->whereIn('learner_id', $children)->where('valid_on', $scope->day)->whereNull('ended_at')->distinct()->count('learner_id');
            $left = max(0, count($children) - $released);
            $items[] = ['key' => 'pickup:'.$c['id'], 'order' => $forToday > 0 ? 18 : 50, 'title' => 'Pick-up today',
                'sub' => "{$left} to hand over".($forToday > 0 ? " · {$forToday} with someone named for today only" : ''),
                'count' => $forToday > 0 ? '! Check' : (string) $left, 'tone' => $forToday > 0 ? 'attention' : 'plain', 'href' => '/pickup'];
        }

        return $items === [] ? [] : ['items' => $items];
    }
}
