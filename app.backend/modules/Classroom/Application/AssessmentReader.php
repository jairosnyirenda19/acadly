<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Acadly\Modules\Classroom\Contracts\ContinuousAssessment;
use Illuminate\Support\Facades\DB;

/** The Classroom contract Results reads (D-105). */
final class AssessmentReader implements ContinuousAssessment
{
    public function __construct(
        private readonly Courses $courses,
        private readonly Clock $clock,
    ) {}

    public function of(string $offeringId): array
    {
        $tasks = array_values(DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->orderBy('due_at')->get()->all());
        $totals = [];
        foreach (ClassroomViews::grid($tasks, $this->courses->students($offeringId), $this->clock->now(), null) as $row) {
            $totals[$row['learner_id']] = $row['total'];
        }

        return ['share' => CourseActions::share($offeringId), 'totals' => $totals,
            'unreleased' => count(array_filter($tasks, fn (object $t): bool => ($t->released_at ?? null) === null))];
    }

    public function parts(string $offeringId, string $learnerId): array
    {
        $out = [];
        foreach (DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->whereNotNull('released_at')->orderBy('due_at')->get() as $t) {
            $m = Submissions::marks(Row::text($t, 'id'))[$learnerId] ?? null;
            $weight = (float) Row::text($t, 'weight');
            $outOf = max(1, Row::int($t, 'out_of'));
            $out[] = ['title' => Row::text($t, 'title'), 'weight' => $weight, 'mark' => $m['mark'] ?? null, 'out_of' => $outOf,
                'earned' => round($weight * (($m['mark'] ?? 0.0) / $outOf), 1)];
        }

        return $out;
    }
}
