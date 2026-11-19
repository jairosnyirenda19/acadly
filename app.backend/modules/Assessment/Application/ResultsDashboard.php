<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;

/**
 * Results' part of the dashboards (D-100), for those who may see a division's
 * results: this term's grades so far, from locked marks, by division (by
 * subject inside a division), how many classes are published, and the learners
 * below the pass mark. A division that records development steps instead of
 * marks adds nothing here. Counts only: no learner is named.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class ResultsDashboard implements DashboardPanels
{
    /** The mean below which a subject is marked for attention. */
    private const int PASS = 50;

    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly TermGrades $grades,
        private readonly Publishing $publishing,
    ) {}

    public function key(): string
    {
        return 'assessment';
    }

    public function views(DashboardScope $scope): array
    {
        return $this->graded($scope) === [] ? [] : ['results'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        if ($view !== 'results') {
            return [];
        }

        $seen = $this->graded($scope);
        $year = $this->calendar->currentYear($scope->day);
        $by = [];
        $subjects = [];
        $failing = [];
        $published = 0;
        $waiting = 0;
        $classes = 0;

        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $class) {
            if (! in_array($class['division_id'], $seen, true)) {
                continue;
            }
            $period = $this->teaching->periodAt($class['division_id'], $scope->day);
            $learners = array_column($this->learners->learnersIn($class['id'], $scope->day), 'id');
            if ($period === null || $learners === []) {
                continue;
            }
            $classes++;
            $latest = $this->publishing->latest($class['id'], $period['id']);
            if ($latest !== null) {
                $published++;
            } elseif ($this->publishing->current($class, $period)['ready']) {
                $waiting++;
            }
            $result = $this->grades->forClass($class, $period['id'], $learners);
            $names = array_column($result['subjects'], 'name', 'id');
            foreach ($result['grades'] as $subject => $byLearner) {
                foreach ($byLearner as $learner => $w) {
                    if ($w['percent'] === null || $w['passes'] === null) {
                        continue;
                    }
                    $d = $class['division_id'];
                    $top = $result['scale'] !== null && $w['grade'] === ($result['scale']['bands'][0]['grade'] ?? null);
                    $band = $w['passes'] ? ($top ? 0 : 1) : 2;
                    $by[$d][$band] = ($by[$d][$band] ?? 0) + 1;
                    $name = $names[$subject] ?? '';
                    $subjects[$name][0] = ($subjects[$name][0] ?? 0) + $w['percent'];
                    $subjects[$name][1] = ($subjects[$name][1] ?? 0) + 1;
                    if (! $w['passes']) {
                        $failing[$learner] = true;
                    }
                }
            }
        }

        $graded = array_sum(array_map('array_sum', $by));
        $passed = array_sum(array_map(fn (array $b): int => ($b[0] ?? 0) + ($b[1] ?? 0), $by));
        $rate = Panels::percent($passed, $graded);
        $rows = [];
        $worst = null;
        foreach ($seen as $d) {
            $values = [$by[$d][0] ?? 0, $by[$d][1] ?? 0, $by[$d][2] ?? 0];
            $all = array_sum($values);
            if ($all === 0) {
                continue;
            }
            $share = (int) round($values[2] / $all * 100);
            $rows[] = ['name' => $scope->name($d), 'division_id' => $d, 'values' => $values, 'note' => (100 - $share).'% pass'];
            if ($worst === null || $share > $worst[1]) {
                $worst = [$scope->name($d), $share];
            }
        }

        $out = [
            Panels::kpi('pass_rate', $view, 11, 'Pass rate', $rate ?? 0, 'percent', $graded === 0 ? 'No locked marks this term yet' : 'This term, from locked marks', 'ink'),
            Panels::kpi('published', $view, 12, 'Results published', $published, 'count', 'Of '.$classes.' classes'),
            Panels::kpi('to_publish', $view, 13, 'Ready to publish', $waiting, 'count', 'Classes with every mark locked', $waiting > 0 ? 'attention' : 'plain'),
            Panels::kpi('below_pass', $view, 14, 'Below pass', count($failing), 'count', 'Learners in 1 or more subjects'),
            Panels::make('results_bands', $view, 30, 'half', 'bands', 'This term · share of grades in each band', $scope->school ? 'Results by division' : 'Results this term',
                $worst === null ? 'No locked marks this term yet.' : (count($rows) > 1 ? "{$worst[0]} has the largest share below pass: {$worst[1]}%." : "{$worst[1]}% of grades are below pass."),
                ['unit' => 'count', 'names' => ['Top band', 'Passed', 'Below pass'], 'below' => 2, 'rows' => $rows]),
        ];

        $means = [];
        foreach ($subjects as $name => [$sum, $n]) {
            if ($name !== '') {
                $means[(string) $name] = round($sum / $n, 1);
            }
        }
        asort($means);
        if ($means !== []) {
            $labels = array_keys($means);
            $values = array_values($means);
            // Marigold only for a subject below the pass mark: attention, never decoration.
            $below = $values[0] < self::PASS;
            $out[] = Panels::make('results_subjects', $view, 40, $scope->school ? 'half' : 'full', 'columns', 'This term · mean mark by subject', 'Subjects, lowest to highest',
                $below ? $labels[0].' is below the pass mark: '.$values[0].'%.' : $labels[0].' is lowest: '.$values[0].'%. Every subject is above the pass mark.',
                ['labels' => $labels, 'unit' => 'percent', 'stacked' => false, 'highlight' => $below ? 0 : null,
                    'series' => [['name' => 'Mean mark', 'division_id' => null, 'hatched' => false, 'values' => $values]]]);
        }

        return $out;
    }

    /**
     * The divisions of the scope with marks and grades, where the person may see results.
     *
     * @return list<string>
     */
    private function graded(DashboardScope $scope): array
    {
        $marks = array_values(array_filter($scope->divisions, fn (array $d): bool => strtok($d['profile'], '@') !== 'ECD'));
        $ids = array_column($marks, 'id');

        return array_values(array_filter($scope->sees(AssessmentPermission::View), fn (string $id): bool => in_array($id, $ids, true)));
    }
}
