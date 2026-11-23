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
 * or one not yet decided, counts in neither. D-106: a course taken again is
 * a new attempt; the latest graded attempt counts in the cumulative GPA, and
 * each semester carries the cumulative GPA so far and the standing it gives.
 *
 * @phpstan-import-type Line from Sheets
 *
 * @phpstan-type CourseResult array{offering_id: string, course_id: string, attempt: int, counts: bool, code: string, title: string, credits: int, ca: float|null, exam: float|null, mark: int|null, grade: string|null, points: float|null, result: string, sitting: string, released: bool, changed: bool}
 * @phpstan-type Semester array{period: array{id: string, name: string, starts_on: string}, division_id: string, courses: list<CourseResult>, credits: int, earned: int, gpa: float|null, cgpa: float|null, standing: string|null, decision: string, supplementary: int, deferred: int}
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
            $courses[] = ['offering_id' => $o['id'], 'course_id' => $o['course_id'], 'attempt' => 1, 'counts' => true, 'code' => $o['code'], 'title' => $o['title'], 'credits' => $o['credits'], 'ca' => $line['ca'] ?? null,
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
            'gpa' => Scales::gpa(array_map(fn (array $c): array => ['points' => (float) $c['points'], 'credits' => $c['credits']], $graded)), 'cgpa' => null, 'standing' => null,
            'decision' => $repeat > 0 ? 'repeat' : ($supp > 0 ? 'supplementary' : ($deferred > 0 ? 'deferred' : (count(array_filter($courses, fn (array $c): bool => $c['result'] === 'missing')) > 0 ? 'pending' : 'pass'))),
            'supplementary' => $supp, 'deferred' => $deferred];
    }

    /**
     * Every semester with results for a student, newest first, and the cumulative GPA.
     *
     * @return array{semesters: list<Semester>, cumulative: float|null, credits: int, earned: int}
     */
    public function history(string $learnerId, bool $releasedOnly): array
    {
        $q = DB::table('results_sheets as s')->join('academic_periods as p', 'p.id', '=', 's.period_id')->distinct();
        if ($releasedOnly) {
            $q->where('s.state', State::Released->value);
        }
        $semesters = [];
        foreach ($q->orderBy('p.starts_on')->get(['s.division_id', 'p.id', 'p.name', 'p.starts_on', 'p.ends_on']) as $p) {
            $period = ['id' => Row::text($p, 'id'), 'name' => Row::text($p, 'name'), 'starts_on' => Row::text($p, 'starts_on'), 'ends_on' => Row::text($p, 'ends_on')];
            if ($this->teaching->offeringsOf($learnerId, $period['id']) === []) {
                continue;
            }
            $sem = $this->semester(Row::text($p, 'division_id'), $period, $learnerId, $releasedOnly);
            if ($sem['courses'] !== []) {
                $semesters[] = $sem;
            }
        }

        // Oldest first: number the attempts, and work out the cumulative GPA and standing after each semester.
        $last = [];
        foreach ($semesters as $i => $sem) {
            foreach ($sem['courses'] as $j => $c) {
                $last[$c['course_id']] = "{$i}:{$j}";
            }
        }
        $count = [];
        $latest = [];
        $ordered = [];
        foreach ($semesters as $i => $sem) {
            $courses = [];
            foreach ($sem['courses'] as $j => $c) {
                $count[$c['course_id']] = ($count[$c['course_id']] ?? 0) + 1;
                if ($c['points'] !== null) {
                    $latest[$c['course_id']] = ['points' => $c['points'], 'credits' => $c['credits'], 'pass' => $c['result'] === 'pass'];
                }
                $courses[] = [...$c, 'attempt' => $count[$c['course_id']], 'counts' => $last[$c['course_id']] === "{$i}:{$j}"];
            }
            $cgpa = Scales::gpa(array_values(array_map(fn (array $l): array => ['points' => $l['points'], 'credits' => $l['credits']], $latest)));
            $standing = $cgpa === null ? null : ($cgpa >= Rules::for($sem['division_id'], $sem['period']['starts_on'])['good_cgpa'] ? 'good' : 'probation');
            $ordered[] = [...$sem, 'courses' => $courses, 'cgpa' => $cgpa, 'standing' => $standing];
        }
        $counted = array_values(array_map(fn (array $l): array => ['points' => $l['points'], 'credits' => $l['credits']], $latest));

        return ['semesters' => array_reverse($ordered), 'cumulative' => Scales::gpa($counted), 'credits' => array_sum(array_column($counted, 'credits')),
            'earned' => array_sum(array_map(fn (array $l): int => $l['pass'] ? $l['credits'] : 0, $latest))];
    }
}
