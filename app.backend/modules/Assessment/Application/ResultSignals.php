<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Results' signals and figures (D-093): a learner's term result (the average
 * of the subject percentages that have one) and the subjects below the pass
 * mark, worked out the same way as the term grades on the Results tab.
 *
 * @phpstan-import-type ClassRow from Reach
 * @phpstan-import-type Period from \Acadly\Core\Academics\Contracts\Teaching
 */
final class ResultSignals implements LearnerSignals
{
    public function __construct(
        private readonly TermGrades $grades,
        private readonly Teaching $teaching,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'assessment';
    }

    public function label(): string
    {
        return 'Results';
    }

    public function rules(): array
    {
        return [
            ['key' => 'assessment.drop', 'text' => 'Term result down {value} points or more on last term', 'default' => 10],
            ['key' => 'assessment.below_pass', 'text' => '{value} subjects or more below the pass mark', 'default' => 3],
        ];
    }

    public function signals(array $learnerIds, array $rules): array
    {
        $today = $this->clock->today();
        $drop = $rules['assessment.drop'] ?? 0;
        $below = $rules['assessment.below_pass'] ?? 0;
        $groups = [];

        foreach ($learnerIds as $id) {
            $place = $this->directory->placeOf($id);
            $period = $place === null ? null : $this->teaching->periodOn($place['division_id'], $today);

            if ($place !== null && $period !== null) {
                $groups[$place['class_id'].'|'.$period['id']]['class'] = $place['class_id'];
                $groups[$place['class_id'].'|'.$period['id']]['period'] = $period;
                $groups[$place['class_id'].'|'.$period['id']]['learners'][] = $id;
            }
        }

        $out = [];

        foreach ($groups as $g) {
            $now = $this->figures($g['class'], $g['period'], $g['learners']);
            $before = $this->teaching->periodAt($g['period']['division_id'], CarbonImmutable::parse($g['period']['starts_on'])->subDay()->toDateString());

            foreach ($g['learners'] as $id) {
                $f = $now[$id] ?? null;

                if ($f === null) {
                    continue;
                }

                if ($below > 0 && $f['below'] >= $below) {
                    $out[$id][] = "{$f['below']} subjects below the pass mark.";
                }

                if ($drop > 0 && $f['result'] !== null && $before !== null) {
                    $class = $this->directory->classOn($id, $before['ends_on']);
                    $was = $class === null ? null : ($this->figures($class, $before, [$id])[$id]['result'] ?? null);

                    if ($was !== null && $was - $f['result'] >= $drop) {
                        $out[$id][] = 'Term result '.round($f['result']).'%, down '.round($was - $f['result']).' points on last term.';
                    }
                }
            }
        }

        return $out;
    }

    public function trends(string $learnerId, array $terms): array
    {
        $today = $this->clock->today();
        $result = [];
        $below = [];

        foreach ($terms as $term) {
            $period = $this->teaching->period($term['key']);
            $class = $period === null ? null : $this->directory->classOn($learnerId, min($period['ends_on'], $today));
            $f = $class === null ? null : ($this->figures($class, $period, [$learnerId])[$learnerId] ?? null);
            $result[$term['key']] = $f['result'] ?? null;
            $below[$term['key']] = $f === null ? null : (float) $f['below'];
        }

        return [
            ['key' => 'assessment.result', 'label' => 'Term result', 'unit' => '%', 'values' => $result, 'worse' => 'down'],
            ['key' => 'assessment.below_pass', 'label' => 'Subjects below the pass mark', 'unit' => '', 'values' => $below, 'worse' => 'up'],
        ];
    }

    /**
     * @param  Period  $period
     * @param  list<string>  $learnerIds
     * @return array<string, array{result: float|null, below: int}>
     */
    private function figures(string $classId, array $period, array $learnerIds): array
    {
        $class = null;

        foreach ($this->calendar->classesInYear($period['year_id']) as $c) {
            if ($c['id'] === $classId) {
                $class = $c;
            }
        }

        if ($class === null || $class['division_id'] !== $period['division_id']) {
            return [];
        }

        $grades = $this->grades->forClass($class, $period['id'], $learnerIds)['grades'];
        $out = [];

        foreach ($learnerIds as $id) {
            $percents = [];
            $failing = 0;

            foreach ($grades as $bySubject) {
                $w = $bySubject[$id] ?? null;

                if ($w !== null && $w['percent'] !== null) {
                    $percents[] = $w['percent'];
                    $failing += $w['passes'] === false ? 1 : 0;
                }
            }

            $out[$id] = ['result' => $percents === [] ? null : round(array_sum($percents) / count($percents), 1), 'below' => $failing];
        }

        return $out;
    }
}
