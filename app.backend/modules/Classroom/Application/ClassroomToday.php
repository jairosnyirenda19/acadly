<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Illuminate\Support\Facades\DB;

/**
 * Classroom on Today (D-104, D-100): for a lecturer, the hand-ins waiting
 * for their mark, in the courses they run.
 */
final class ClassroomToday implements TodayItems
{
    public function __construct(
        private readonly Courses $courses,
        private readonly ClassroomViews $views,
    ) {}

    public function key(): string
    {
        return 'classroom';
    }

    public function today(DashboardScope $scope): array
    {
        if ($scope->school) {
            return [];
        }
        $waiting = 0;
        $courses = 0;
        foreach ($scope->sees(ClassroomPermission::Teach) as $d) {
            $period = $this->courses->current($d);
            if ($period === null) {
                continue;
            }
            foreach ($this->courses->offerings($d, $period['id']) as $o) {
                if (! $this->courses->teaches($scope->viewer, ['offering' => $o, 'period' => $period, 'division_id' => $d])) {
                    continue;
                }
                $n = 0;
                foreach (DB::table('classroom_tasks')->where('offering_id', $o['id'])->whereNull('removed_at')->whereNull('released_at')->get() as $t) {
                    $n += $this->views->counts($t)['to_mark'];
                }
                $waiting += $n;
                $courses += $n > 0 ? 1 : 0;
            }
        }

        return $waiting === 0 ? [] : ['items' => [['key' => 'classroom_marking', 'order' => 12, 'title' => 'Hand-ins to mark',
            'sub' => $courses === 1 ? 'In 1 course' : "In {$courses} courses", 'count' => (string) $waiting, 'tone' => 'attention', 'href' => '/classroom']]];
    }
}
