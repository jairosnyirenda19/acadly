<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Illuminate\Support\Facades\DB;

/**
 * A student's results by semester (D-105): each course's final result, the
 * semester's credit-weighted GPA and the cumulative GPA. A deferred course,
 * or one not yet decided, counts in neither.
 *
 * @phpstan-import-type Line from Sheets
 *
 * @phpstan-type CourseResult array{offering_id: string, code: string, title: string, credits: int, ca: float|null, exam: float|null, mark: int|null, grade: string|null, points: float|null, result: string, sitting: string, released: bool, changed: bool}
 * @phpstan-type Semester array{period: array{id: string, name: string, starts_on: string}, division_id: string, courses: list<CourseResult>, credits: int, earned: int, gpa: float|null, decision: string, supplementary: int, deferred: int}
 */
final class Standing
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly CourseTeaching $teaching,
    ) {}

    /**
     * One semester of a student.
     *
     * @param  array{id: string, name: string, starts_on: string, ends_on: string}  $period
     * @return Semester
     */
    public function semester(string $divisionId, array $period, string $learnerId, bool $releasedOnly): array
    {
        $held = $this->teaching->offeringsOf($learnerId, $period['id']);
        $courses = [];
        foreach ($this->sheets->offerings($divisionId, $period['id']) as $o) {
            if (! in_array($o['id'], $held, true)) {
                continue;
            }
            $course = ['offering' => $o, 'period' => $period, 'division_id' => $divisionId];
            $line = $this->sheets->finalLine($course, $learnerId, $releasedOnly);
            $sitting = $line !== null && $line['kind'] !== 'first' ? 'supplementary' : 'main';
            $sheet = $this->sheets->sheet($o['id'], $sitting);
            $released = $sheet !== null && $sheet->state === State::Released->value;
            if ($releasedOnly && $line === null) {
                continue;
            }
            $courses[] = ['offering_id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'credits' => $o['credits'], 'ca' => $line['ca'] ?? null,
                'exam' => $line === null ? null : ($line['absence'] === 'absent' ? 0.0 : $line['exam']), 'mark' => $line['mark'] ?? null,
                'grade' => $line['grade'] ?? null, 'points' => $line['points'] ?? null, 'result' => $line['result'] ?? 'missing', 'sitting' => $sitting, 'released' => $released,
                'changed' => $line['changed'] ?? false];
        }
        $graded = array_values(array_filter($courses, fn (array $c): bool => $c['points'] !== null));
        $supp = count(array_filter($courses, fn (array $c): bool => in_array($c['result'], ['fail', 'barred'], true)));
        $deferred = count(array_filter($courses, fn (array $c): bool => $c['result'] === 'deferred'));
        $repeat = count(array_filter($courses, fn (array $c): bool => $c['result'] === 'repeat'));

        return ['period' => ['id' => $period['id'], 'name' => $period['name'], 'starts_on' => $period['starts_on']], 'division_id' => $divisionId, 'courses' => $courses,
            'credits' => array_sum(array_column($graded, 'credits')),
            'earned' => array_sum(array_column(array_filter($graded, fn (array $c): bool => $c['result'] === 'pass'), 'credits')),
            'gpa' => Scales::gpa(array_map(fn (array $c): array => ['points' => (float) $c['points'], 'credits' => $c['credits']], $graded)),
            'decision' => $repeat > 0 ? 'repeat' : ($supp > 0 ? 'supplementary' : ($deferred > 0 ? 'deferred' : (count(array_filter($courses, fn (array $c): bool => $c['result'] === 'missing')) > 0 ? 'pending' : 'pass'))),
            'supplementary' => $supp, 'deferred' => $deferred];
    }

    /**
     * Every semester with results for a student, newest first, and the cumulative GPA.
     *
     * @return array{semesters: list<Semester>, cumulative: float|null, credits: int}
     */
    public function history(string $learnerId, bool $releasedOnly): array
    {
        $q = DB::table('results_sheets as s')->join('academic_periods as p', 'p.id', '=', 's.period_id')->distinct();
        if ($releasedOnly) {
            $q->where('s.state', State::Released->value);
        }
        $semesters = [];
        $all = [];
        foreach ($q->orderByDesc('p.starts_on')->get(['s.division_id', 'p.id', 'p.name', 'p.starts_on', 'p.ends_on']) as $p) {
            $period = ['id' => Row::text($p, 'id'), 'name' => Row::text($p, 'name'), 'starts_on' => Row::text($p, 'starts_on'), 'ends_on' => Row::text($p, 'ends_on')];
            if ($this->teaching->offeringsOf($learnerId, $period['id']) === []) {
                continue;
            }
            $sem = $this->semester(Row::text($p, 'division_id'), $period, $learnerId, $releasedOnly);
            if ($sem['courses'] === []) {
                continue;
            }
            $semesters[] = $sem;
            foreach ($sem['courses'] as $c) {
                if ($c['points'] !== null) {
                    $all[] = ['points' => $c['points'], 'credits' => $c['credits']];
                }
            }
        }

        return ['semesters' => $semesters, 'cumulative' => Scales::gpa($all), 'credits' => array_sum(array_column($all, 'credits'))];
    }
}
