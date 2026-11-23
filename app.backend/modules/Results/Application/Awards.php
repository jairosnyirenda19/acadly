<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Programmes\Contracts\StudentProgress;
use Acadly\Modules\Results\Domain\AwardList;
use Acadly\Modules\Results\Journeys\Award\State;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * The graduation list of a programme (D-106): its final-year students, who
 * has completed it (every core course passed, the programme's credits passed
 * and the final cumulative GPA at the lowest class or more), and the class
 * of award from the rules. Once the board approves, the list is kept as rows.
 * The certificate waits while fees are owed when the rules say so.
 *
 * @phpstan-import-type ProgrammeRow from StudentProgress
 *
 * @phpstan-type AwardRow array{learner_id: string, name: string, number: string, cgpa: float|null, credits: int, class: string|null, complete: bool, missing: list<string>, certificate: string}
 */
final class Awards
{
    public function __construct(
        private readonly Progression $progression,
        private readonly Standing $standing,
        private readonly CourseTeaching $teaching,
        private readonly StudentProgress $progress,
        private readonly ModuleActivation $modules,
        private readonly Container $container,
    ) {}

    public function find(string $divisionId, string $programmeId): ?AwardList
    {
        return AwardList::query()->where('division_id', $divisionId)->where('period_id', $this->progression->year($divisionId)['last']['id'])
            ->where('programme_id', $programmeId)->first();
    }

    /** @return list<AwardRow> */
    public function rows(string $divisionId, string $programmeId, ?AwardList $list): array
    {
        $programme = $this->progression->programme($divisionId, $programmeId);
        $rules = Rules::for($divisionId, $this->progression->year($divisionId)['last']['starts_on']);
        $out = [];
        if ($list !== null && $list->state !== State::WorkedOut->value) {
            $kept = [];
            foreach (DB::table('results_awards')->where('list_id', $list->id)->orderBy('created_at')->get() as $a) {
                $kept[Row::text($a, 'learner_id')] = $a;
            }
            foreach ($kept as $learnerId => $a) {
                $file = $this->teaching->studentFile($learnerId);
                $missing = json_decode(Row::text($a, 'missing'), true);
                $out[] = ['learner_id' => $learnerId, 'name' => $file['name'] ?? '', 'number' => $file['number'] ?? '',
                    'cgpa' => is_numeric($a->cgpa ?? null) ? (float) $a->cgpa : null, 'credits' => Row::int($a, 'credits'), 'class' => Row::maybeText($a, 'class'),
                    'complete' => (bool) $a->complete, 'missing' => is_array($missing) ? array_values(array_filter($missing, 'is_string')) : [],
                    'certificate' => $this->certificate($divisionId, $learnerId, $rules['hold_certificate'])];
            }
        } else {
            foreach ($this->progress->students($programmeId, $programme['years']) as $learnerId) {
                $out[] = $this->work($divisionId, $learnerId, $programme, $rules);
            }
        }
        usort($out, fn (array $a, array $b): int => [$b['complete'], $b['cgpa'] ?? 0] <=> [$a['complete'], $a['cgpa'] ?? 0]);

        return $out;
    }

    /**
     * @param  ProgrammeRow  $programme
     * @param  array{id: string|null, good_cgpa: float, carry_credits: int, max_attempts: int, time_factor: int, classes: array{diploma: list<array{name: string, from: float}>, degree: list<array{name: string, from: float}>}, hold_certificate: bool, from_on: string|null}  $rules
     * @return AwardRow
     */
    private function work(string $divisionId, string $learnerId, array $programme, array $rules): array
    {
        $file = $this->teaching->studentFile($learnerId);
        $history = $this->standing->history($learnerId, true);
        $passedCourses = [];
        foreach ($history['semesters'] as $s) {
            foreach ($s['courses'] as $c) {
                if ($c['counts'] && $c['result'] === 'pass') {
                    $passedCourses[] = $c['course_id'];
                }
            }
        }
        $missing = [];
        foreach ($this->progress->coreCourses($learnerId) as $core) {
            if (! in_array($core['course_id'], $passedCourses, true)) {
                $missing[] = $core['code'];
            }
        }
        $cgpa = $history['cumulative'];
        $complete = $missing === [] && $history['earned'] >= $programme['credits'] && $cgpa !== null && $cgpa >= Rules::passFrom($rules, $programme['award']);

        return ['learner_id' => $learnerId, 'name' => $file['name'] ?? '', 'number' => $file['number'] ?? '', 'cgpa' => $cgpa, 'credits' => $history['earned'],
            'class' => $complete ? Rules::classOf($rules, $programme['award'], $cgpa) : null, 'complete' => $complete, 'missing' => $missing,
            'certificate' => $this->certificate($divisionId, $learnerId, $rules['hold_certificate'])];
    }

    /** 'cleared', or 'held' while fees are owed and the rules hold the certificate. */
    public function certificate(string $divisionId, string $learnerId, bool $hold): string
    {
        if (! $hold || ! $this->modules->isActive('fees', ScopeRef::of(ScopeType::Division, $divisionId)) || ! $this->container->bound(FeesStanding::class)) {
            return 'cleared';
        }
        $f = $this->container->make(FeesStanding::class)->owedAndPaid($learnerId);

        return $f['paid'] < $f['owed'] ? 'held' : 'cleared';
    }

    /**
     * A student's award, once the academic board confirmed it.
     *
     * @return array{programme: string, class: string|null, cgpa: float|null, credits: int, state: string, graduation_on: string|null, certificate: string}|null
     */
    public function of(string $learnerId): ?array
    {
        $a = DB::table('results_awards as a')->join('results_award_lists as l', 'l.id', '=', 'a.list_id')->where('a.learner_id', $learnerId)->where('a.complete', true)
            ->whereIn('l.state', [State::Confirmed->value, State::Conferred->value])->orderByDesc('a.created_at')
            ->first(['a.class', 'a.cgpa', 'a.credits', 'l.state', 'l.graduation_on', 'l.division_id', 'l.programme_id']);
        if ($a === null) {
            return null;
        }
        $programme = $this->progression->programme(Row::text($a, 'division_id'), Row::text($a, 'programme_id'));
        $rules = Rules::for(Row::text($a, 'division_id'), $this->progression->year(Row::text($a, 'division_id'))['last']['starts_on']);

        return ['programme' => $programme['name'], 'class' => Row::maybeText($a, 'class'), 'cgpa' => is_numeric($a->cgpa ?? null) ? (float) $a->cgpa : null,
            'credits' => Row::int($a, 'credits'), 'state' => Row::text($a, 'state'), 'graduation_on' => Row::maybeText($a, 'graduation_on'),
            'certificate' => $this->certificate(Row::text($a, 'division_id'), $learnerId, $rules['hold_certificate'])];
    }
}
