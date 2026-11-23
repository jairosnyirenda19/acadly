<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Programmes\Contracts\StudentProgress;
use Acadly\Modules\Results\Domain\ProgressionList;
use Acadly\Modules\Results\Journeys\Progression\State;
use Acadly\Modules\Results\Journeys\Sheet\State as SheetState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Works out each student's end-of-year decision (D-106) from the released
 * results and the rules: Proceed, Proceed with courses carried (at most the
 * rules' carried credits), Repeat the year, or Complete in the final year.
 * The rules leave a student to the board when they are on probation a second
 * time in a row, have used their attempts at a failed course, or are past the
 * time limit; the board then chooses, with a reason. Results not yet complete
 * (a deferred exam, a supplementary paper not released) leave the student
 * waiting.
 *
 * @phpstan-import-type ProgrammeRow from StudentProgress
 * @phpstan-import-type RuleSet from Rules
 *
 * @phpstan-type ProgressionRow array{learner_id: string, name: string, number: string, cgpa: float|null, credits: int, passed: int, failed_credits: int, failed: list<array{course_id: string, code: string}>, standing: string|null, proposed: string|null, fallback: string|null, why: string, choice: array{decision: string, reason: string, by: string, at: string}|null, decision: string|null}
 */
final class Progression
{
    public const array CHOICES = ['proceed', 'carry', 'repeat_year', 'discontinue', 'complete', 'continue'];

    public function __construct(
        private readonly Sheets $sheets,
        private readonly Standing $standing,
        private readonly CourseTeaching $teaching,
        private readonly StudentProgress $progress,
    ) {}

    /**
     * The semesters of the year a list closes, and its last one.
     *
     * @return array{periods: list<array{id: string, name: string, starts_on: string, ends_on: string}>, last: array{id: string, name: string, starts_on: string, ends_on: string}}
     */
    public function year(string $divisionId): array
    {
        $periods = $this->sheets->periods($divisionId);
        $last = $periods === [] ? throw new NotFoundHttpException : $periods[count($periods) - 1];

        return ['periods' => $periods, 'last' => $last];
    }

    /** @return ProgrammeRow */
    public function programme(string $divisionId, string $programmeId): array
    {
        foreach ($this->progress->programmes($divisionId) as $p) {
            if ($p['id'] === $programmeId) {
                return $p;
            }
        }
        throw new NotFoundHttpException;
    }

    public function find(string $divisionId, string $programmeId, int $year): ?ProgressionList
    {
        return ProgressionList::query()->where('division_id', $divisionId)->where('period_id', $this->year($divisionId)['last']['id'])
            ->where('programme_id', $programmeId)->where('year_of_study', $year)->first();
    }

    /**
     * Every student of a list with the decision worked out, the board's choice and the decision in force.
     *
     * @return list<ProgressionRow>
     */
    public function rows(ProgressionList $list): array
    {
        return $this->rowsFor($list->division_id, $list->programme_id, $list->year_of_study, $list);
    }

    /** @return list<ProgressionRow> */
    public function rowsFor(string $divisionId, string $programmeId, int $year, ?ProgressionList $list): array
    {
        $programme = $this->programme($divisionId, $programmeId);
        $yearInfo = $this->year($divisionId);
        $periods = array_column($yearInfo['periods'], 'id');
        $rules = Rules::for($divisionId, $yearInfo['last']['starts_on']);
        $decided = [];
        $choices = [];
        if ($list !== null) {
            foreach (DB::table('results_progression_decisions')->where('list_id', $list->id)->orderBy('created_at')->get() as $d) {
                $decided[Row::text($d, 'learner_id')] = $d;
            }
            foreach (DB::table('results_progression_choices as c')->join('users as u', 'u.id', '=', 'c.by_user_id')->where('c.list_id', $list->id)->orderBy('c.created_at')
                ->get(['c.learner_id', 'c.decision', 'c.reason', 'c.created_at', 'u.name']) as $c) {
                $choices[Row::text($c, 'learner_id')] = ['decision' => Row::text($c, 'decision'), 'reason' => Row::text($c, 'reason'), 'by' => Row::text($c, 'name'), 'at' => Row::text($c, 'created_at')];
            }
        }
        $frozen = $list !== null && $list->state !== State::WorkedOut->value;
        $learners = $frozen ? array_keys($decided) : $this->progress->students($programmeId, $year);
        $out = [];
        foreach ($learners as $learnerId) {
            $row = $this->work($learnerId, $programme, $year, $periods, $rules);
            $choice = $choices[$learnerId] ?? null;
            $row['choice'] = $choice;
            if ($frozen && isset($decided[$learnerId])) {
                $row['decision'] = Row::text($decided[$learnerId], 'decision');
            } else {
                $pick = $choice['decision'] ?? $row['proposed'];
                $row['decision'] = $pick === 'continue' ? $row['fallback'] : $pick;
            }
            $out[] = $row;
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * One student's year, worked out.
     *
     * @param  ProgrammeRow  $programme
     * @param  list<string>  $periods
     * @param  RuleSet  $rules
     * @return ProgressionRow
     */
    private function work(string $learnerId, array $programme, int $year, array $periods, array $rules): array
    {
        $file = $this->teaching->studentFile($learnerId);
        $history = $this->standing->history($learnerId, true);
        $thisYear = array_values(array_filter($history['semesters'], fn (array $s): bool => in_array($s['period']['id'], $periods, true)));
        $credits = 0;
        $passed = 0;
        $failed = [];
        $pending = [];
        $outOfAttempts = false;
        foreach ($thisYear as $s) {
            foreach ($s['courses'] as $c) {
                if (! $c['counts']) {
                    continue;
                }
                $credits += $c['credits'];
                if ($c['result'] === 'pass') {
                    $passed += $c['credits'];

                    continue;
                }
                $supp = $this->sheets->sheet($c['offering_id'], 'supplementary');
                if (in_array($c['result'], ['missing', 'deferred'], true) || (in_array($c['result'], ['fail', 'barred'], true) && $supp !== null && $supp->state !== SheetState::Released->value)) {
                    $pending[] = $c['code'];

                    continue;
                }
                $failed[] = ['course_id' => $c['course_id'], 'code' => $c['code'], 'credits' => $c['credits']];
                $outOfAttempts = $outOfAttempts || $c['attempt'] >= $rules['max_attempts'];
            }
        }
        $failedCredits = array_sum(array_column($failed, 'credits'));
        $standings = array_values(array_filter(array_column($history['semesters'], 'standing')));
        $secondProbation = count($standings) >= 2 && $standings[0] === 'probation' && $standings[1] === 'probation';
        $overTime = count($history['semesters']) > $programme['years'] * 2 * max(1, $rules['time_factor']);
        $fallback = $failedCredits === 0 ? ($year >= $programme['years'] ? 'complete' : 'proceed') : ($failedCredits <= $rules['carry_credits'] ? 'carry' : 'repeat_year');
        $codes = implode(', ', array_column($failed, 'code'));
        [$proposed, $why] = match (true) {
            $pending !== [] => [null, 'Results not complete: '.implode(', ', $pending)],
            $secondProbation => [null, 'On probation a second time in a row: the board decides'],
            $outOfAttempts => [null, 'Every attempt at a failed course is used: the board decides'],
            $overTime => [null, 'Past the time limit for the programme: the board decides'],
            $failedCredits === 0 => [$fallback, 'All passed'],
            $fallback === 'carry' => ['carry', "{$failedCredits} failed credits, within the {$rules['carry_credits']} that may be carried: {$codes}"],
            default => ['repeat_year', "{$failedCredits} failed credits, more than the {$rules['carry_credits']} that may be carried: {$codes}"],
        };

        return ['learner_id' => $learnerId, 'name' => $file['name'] ?? '', 'number' => $file['number'] ?? '', 'cgpa' => $history['cumulative'], 'credits' => $credits,
            'passed' => $passed, 'failed_credits' => $failedCredits, 'failed' => array_map(fn (array $f): array => ['course_id' => $f['course_id'], 'code' => $f['code']], $failed),
            'standing' => $history['semesters'][0]['standing'] ?? null, 'proposed' => $proposed, 'fallback' => $pending === [] ? $fallback : null, 'why' => $why,
            'choice' => null, 'decision' => $proposed];
    }

    /**
     * The courses a decision carries.
     *
     * @return list<array{course_id: string, code: string}>
     */
    public static function carried(object $decision): array
    {
        $raw = json_decode(is_string($decision->carried ?? null) ? $decision->carried : '[]', true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            if (is_array($c) && is_string($c['course_id'] ?? null)) {
                $out[] = ['course_id' => $c['course_id'], 'code' => is_string($c['code'] ?? null) ? $c['code'] : ''];
            }
        }

        return $out;
    }

    /**
     * A learner's progression decisions once the board decided them, newest first.
     *
     * @return list<array{period: string, year: int, decision: string, carried: list<string>, cgpa: float|null, reason: string|null, state: string, at: string}>
     */
    public static function decisionsOf(string $learnerId): array
    {
        $latest = [];
        foreach (DB::table('results_progression_decisions as d')->join('results_progression_lists as l', 'l.id', '=', 'd.list_id')->join('academic_periods as p', 'p.id', '=', 'l.period_id')
            ->where('d.learner_id', $learnerId)->whereIn('l.state', [State::Decided->value, State::Applied->value])->orderBy('d.created_at')
            ->get(['d.list_id', 'd.decision', 'd.carried', 'd.cgpa', 'd.reason', 'd.created_at', 'l.state', 'l.year_of_study', 'p.name', 'p.starts_on']) as $d) {
            $latest[Row::text($d, 'list_id')] = $d;
        }
        $out = [];
        foreach ($latest as $d) {
            $out[] = ['period' => Row::text($d, 'name'), 'year' => Row::int($d, 'year_of_study'), 'decision' => Row::text($d, 'decision'),
                'carried' => array_column(self::carried($d), 'code'), 'cgpa' => is_numeric($d->cgpa ?? null) ? (float) $d->cgpa : null, 'reason' => Row::maybeText($d, 'reason'),
                'state' => Row::text($d, 'state'), 'at' => Row::text($d, 'created_at'), 'starts_on' => Row::text($d, 'starts_on')];
        }
        usort($out, fn (array $a, array $b): int => strcmp($b['starts_on'], $a['starts_on']) ?: strcmp($b['at'], $a['at']));

        return array_map(function (array $d): array {
            unset($d['starts_on']);

            return $d;
        }, $out);
    }
}
