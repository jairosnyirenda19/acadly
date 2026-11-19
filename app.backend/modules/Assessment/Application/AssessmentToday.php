<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Results' part of Today (D-100): the marks a teacher still has to enter, the
 * marks sent back to them, the marks waiting for a head to lock; in a nursery
 * room, the children not yet observed this week and the reviews to finish.
 */
final class AssessmentToday implements TodayItems
{
    public function __construct(
        private readonly Assessments $assessments,
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
    ) {}

    public function key(): string
    {
        return 'assessment';
    }

    public function today(DashboardScope $scope): array
    {
        $viewer = $scope->viewer;
        $items = [];
        $mine = $this->assessments->mine($viewer);
        $rows = is_array($mine['assessments'] ?? null) ? $mine['assessments'] : [];
        $toEnter = [];
        $sentBack = [];

        foreach ($rows as $a) {
            if (! is_array($a)) {
                continue;
            }
            $state = is_string($a['state'] ?? null) ? $a['state'] : '';
            $marked = is_int($a['marked'] ?? null) ? $a['marked'] : 0;
            $learners = is_int($a['learners'] ?? null) ? $a['learners'] : 0;
            $title = (is_string($a['subject'] ?? null) ? $a['subject'] : '').' · '.(is_string($a['class'] ?? null) ? $a['class'] : '');
            if ($state === State::Marking->value && $marked < $learners) {
                $toEnter[] = [$title, $marked, $learners, is_string($a['id'] ?? null) ? $a['id'] : ''];
            }
            if ($state === State::Returned->value) {
                $sentBack[] = [$title, is_string($a['id'] ?? null) ? $a['id'] : ''];
            }
        }
        if ($toEnter !== []) {
            [$title, $marked, $learners, $id] = $toEnter[0];
            $items[] = ['key' => 'marks_to_enter', 'order' => 30, 'title' => 'Marks to enter', 'sub' => $title.(count($toEnter) > 1 ? ' and '.(count($toEnter) - 1).' more' : ''),
                'count' => "{$marked} of {$learners}", 'tone' => 'attention', 'href' => count($toEnter) === 1 ? '/marks/'.$id : '/marks'];
        }
        if ($sentBack !== []) {
            $items[] = ['key' => 'marks_sent_back', 'order' => 15, 'title' => 'Marks sent back', 'sub' => $sentBack[0][0].' · to check and send again', 'count' => '! '.count($sentBack), 'tone' => 'wrong',
                'href' => '/marks/'.$sentBack[0][1]];
        }

        // Those who lock marks: what is waiting for them.
        $lockable = array_values(array_filter($this->reach->classes($viewer), fn (array $c): bool => in_array($c['division_id'], $scope->ids(), true) && $this->reach->may($viewer, AssessmentPermission::Lock, $c['id'])));
        if ($lockable !== [] && ! $scope->school) {
            $waiting = DB::table('assessments')->whereIn('class_id', array_column($lockable, 'id'))->where('state', State::Submitted->value)->count();
            if ($waiting > 0) {
                $items[] = ['key' => 'marks_to_lock', 'order' => 12, 'title' => 'Marks waiting for you', 'sub' => 'Submitted, to check and lock', 'count' => (string) $waiting, 'tone' => 'attention', 'href' => '/marks/all'];
            }
        }

        // A nursery room: children not observed this week, and reviews to finish.
        $year = $this->calendar->currentYear($scope->day);
        $rooms = [];
        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $c) {
            if (in_array($c['division_id'], $scope->ids(), true) && $this->calendar->classTeacherId($c['id']) === $viewer->id && $this->isNursery($scope, $c['division_id'])) {
                $rooms[] = $c;
            }
        }
        foreach ($rooms as $room) {
            $monday = CarbonImmutable::parse($scope->day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $children = DB::table('placements')->where('class_id', $room['id'])->whereNull('ends_on')->pluck('learner_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();
            $seen = DB::table('assessment_observations')->where('class_id', $room['id'])->where('observed_on', '>=', $monday)->distinct()->pluck('learner_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();
            $none = count(array_diff($children, $seen));
            if ($none > 0) {
                $items[] = ['key' => 'observations:'.$room['id'], 'order' => 35, 'title' => 'Observations this week', 'sub' => $none === 1 ? '1 child has none yet' : "{$none} children have none yet",
                    'count' => (string) $none, 'tone' => 'attention', 'href' => '/observations'];
            }
            $drafts = DB::table('progress_reviews')->where('class_id', $room['id'])->where('state', 'draft')->pluck('learner_id')->all();
            if ($drafts !== []) {
                $items[] = ['key' => 'reviews:'.$room['id'], 'order' => 45, 'title' => 'Progress reviews', 'sub' => count($drafts).' drafts to finish', 'count' => (string) count($drafts), 'tone' => 'plain',
                    'href' => count($drafts) === 1 && is_string($drafts[0]) ? '/reviews/'.$drafts[0] : '/reviews'];
            }
        }

        return $items === [] ? [] : ['items' => $items];
    }

    private function isNursery(DashboardScope $scope, string $divisionId): bool
    {
        foreach ($scope->divisions as $d) {
            if ($d['id'] === $divisionId) {
                return strtok($d['profile'], '@') === 'ECD';
            }
        }

        return false;
    }
}
