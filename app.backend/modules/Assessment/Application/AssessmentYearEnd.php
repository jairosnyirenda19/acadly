<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Results at the year-end run (D-088): each learner's last published report
 * card this year, and the classes whose last term results are not
 * published, which stop the commit.
 */
final class AssessmentYearEnd implements YearEndCheck
{
    public function __construct(private readonly AcademicCalendar $calendar) {}

    public function column(string $yearId, array $learnerIds): ?array
    {
        if ($learnerIds === []) {
            return null;
        }

        $rows = DB::table('result_publication_learners as l')->join('result_publications as p', 'p.id', '=', 'l.publication_id')
            ->join('academic_periods as t', 't.id', '=', 'p.period_id')
            ->where('t.year_id', $yearId)->whereIn('l.learner_id', $learnerIds)
            ->orderBy('t.position')->orderBy('p.version')->get(['l.learner_id', 'l.card']);
        $values = [];

        foreach ($rows as $r) {
            $card = json_decode(Row::text($r, 'card'), true);
            $summary = is_array($card) && is_array($card['summary'] ?? null) ? $card['summary'] : [];
            $value = $summary['value'] ?? null;

            if (is_int($value) || is_float($value)) {
                $values[Row::text($r, 'learner_id')] = match ($summary['kind'] ?? null) {
                    'average_percent' => round($value).'%',
                    'best_six' => (int) $value.' points',
                    default => number_format((float) $value, 1),
                };
            }
        }

        return ['label' => 'Last report', 'values' => $values];
    }

    public function problems(string $yearId, array $classIds): array
    {
        $problems = [];
        $names = $this->calendar->classNames($classIds);

        foreach ($classIds as $classId) {
            $class = $this->calendar->classInForce($classId);

            if ($class === null || $class['year_id'] !== $yearId) {
                continue;
            }

            $last = DB::table('academic_periods')->where('year_id', $yearId)->where('division_id', $class['division_id'])->orderByDesc('position')->first(['id', 'name']);

            if ($last !== null && ! DB::table('result_publications')->where('class_id', $classId)->where('period_id', Row::text($last, 'id'))->exists()) {
                $problems[] = Row::text($last, 'name').' results of '.($names[$classId]['name'] ?? $class['name']).' are not published.';
            }
        }

        return $problems;
    }
}
