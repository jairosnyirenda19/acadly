<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Results\Application\Actions\SheetActions;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\Sheet;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the console shows of Results (D-105): each semester's courses with
 * their sheets, one sheet with its grade spread, the attendance bar, the
 * examiners' board by programme and year, release and the setup.
 *
 * @phpstan-import-type Course from Sheets
 */
final class ResultsViews
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Standing $standing,
        private readonly CourseTeaching $teaching,
        private readonly SheetActions $actions,
        private readonly JourneyEngine $engine,
    ) {}

    /** @return array<string, mixed> */
    public function courses(User $who, string $divisionId, ?string $periodId): array
    {
        $this->sheets->enter($who, $divisionId);
        $period = $this->sheets->period($divisionId, $periodId);
        $sees = $this->sheets->sees($who, $divisionId);
        $out = [];
        foreach ($this->sheets->offerings($divisionId, $period['id']) as $o) {
            $course = ['offering' => $o, 'period' => $period, 'division_id' => $divisionId];
            $teaches = $this->sheets->teaches($who, $course);
            if (! $teaches && ! $sees) {
                continue;
            }
            $main = $this->sheets->sheet($o['id'], 'main');
            $supp = $this->sheets->sheet($o['id'], 'supplementary');
            $out[] = ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'group' => $o['group'], 'credits' => $o['credits'], 'department' => $o['department'],
                'lecturers' => array_column($o['lecturers'], 'name'), 'students' => $o['taken'], 'teaches' => $teaches,
                'main' => $main === null ? null : ['id' => $main->id, 'state' => $main->state], 'supplementary' => $supp === null ? null : ['id' => $supp->id, 'state' => $supp->state]];
        }

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'periods' => array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], $this->sheets->periods($divisionId)),
            'courses' => $out, 'can' => $this->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function sheet(User $who, string $divisionId, string $offeringId, string $sitting): array
    {
        $course = $this->sheets->open($who, $divisionId, $offeringId);
        $sheet = $this->sheets->sheet($offeringId, $sitting);
        if ($sheet === null && $sitting !== 'main') {
            throw new NotFoundHttpException;
        }
        $scale = $this->sheets->scale($divisionId, $course['period']['id']);
        $lines = $this->sheets->lines($sheet, $course);
        $marks = $sheet === null ? [] : Sheets::marks($sheet->id);
        $settings = Scales::settings($divisionId);
        $assessment = $sheet === null || $sheet->state === State::Entered->value ? $this->sheets->assessment($offeringId, $divisionId) : null;
        $share = $lines[0]['share'] ?? ($assessment['share'] ?? 0);
        $history = [];
        if ($sheet !== null) {
            foreach (DB::table('journey_step_completions as j')->leftJoin('users as u', 'u.id', '=', 'j.actor_user_id')->where('j.subject_type', $sheet->auditType())
                ->where('j.subject_id', $sheet->id)->orderByDesc('j.seq')->get(['j.step', 'j.outcome', 'j.occurred_at', 'j.reason', 'u.name']) as $h) {
                $history[] = ['step' => Row::text($h, 'step'), 'outcome' => Row::text($h, 'outcome'), 'by' => Row::maybeText($h, 'name') ?? '',
                    'at' => CarbonImmutable::parse(Row::text($h, 'occurred_at'))->toIso8601String(), 'reason' => Row::maybeText($h, 'reason')];
            }
        }
        $changes = [];
        foreach ($marks as $learner => $m) {
            if ($m['stage'] !== 'entry') {
                $changes[$learner] = ['stage' => $m['stage'], 'was' => $m['was'], 'reason' => $m['reason'], 'by' => $m['by']];
            }
        }

        return [
            'course' => ['id' => $offeringId, 'code' => $course['offering']['code'], 'title' => $course['offering']['title'], 'group' => $course['offering']['group'],
                'credits' => $course['offering']['credits'], 'department' => $course['offering']['department'], 'period' => $course['period']['name'], 'period_id' => $course['period']['id'],
                'lecturers' => array_column($course['offering']['lecturers'], 'name')],
            'sheet' => $sheet === null ? null : ['id' => $sheet->id, 'state' => $sheet->state, 'sitting' => $sheet->sitting, 'exam_on' => $sheet->exam_on?->toDateString(),
                'released_at' => $sheet->released_at?->toIso8601String()],
            'sitting' => $sitting, 'share' => $share, 'unreleased' => $assessment['unreleased'] ?? 0, 'bar_percent' => $settings['bar_percent'],
            'scale' => ['label' => $scale['label'], 'pass_mark' => $scale['pass_mark'], 'bands' => $scale['bands']],
            'lines' => array_map(fn (array $l): array => [...$l, 'change' => $changes[$l['learner_id']] ?? null], $lines),
            'spread' => Sheets::spread($lines, $scale),
            'teaches' => $this->sheets->teaches($who, $course), 'can' => $this->can($who, $divisionId),
            'steps' => $sheet === null ? [] : array_map(fn ($a): array => $a->toArray(), $this->engine->available($sheet, $who)),
            'history' => $history,
        ];
    }

    /**
     * The attendance bar of a semester: every student below it, course by course.
     *
     * @return array<string, mixed>
     */
    public function bar(User $who, string $divisionId, ?string $periodId): array
    {
        $this->sheets->enter($who, $divisionId);
        $period = $this->sheets->period($divisionId, $periodId);
        $settings = Scales::settings($divisionId);
        $rows = [];
        $close = 0;
        foreach ($this->sheets->offerings($divisionId, $period['id']) as $o) {
            $course = ['offering' => $o, 'period' => $period, 'division_id' => $divisionId];
            if (! $this->sheets->teaches($who, $course) && ! $this->sheets->sees($who, $divisionId)) {
                continue;
            }
            $sheet = $this->sheets->sheet($o['id'], 'main');
            foreach ($this->sheets->lines($sheet, $course) as $l) {
                if ($l['rate'] === null) {
                    continue;
                }
                if ($l['barred'] || $l['lifted'] !== null) {
                    $rows[] = ['offering_id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'learner_id' => $l['learner_id'], 'name' => $l['name'], 'number' => $l['number'],
                        'held' => $l['held'], 'attended' => $l['attended'], 'rate' => $l['rate'], 'lifted' => $l['lifted']];
                } elseif ($l['rate'] < $settings['bar_percent'] + 5) {
                    $close++;
                }
            }
        }
        $ends = CarbonImmutable::parse($period['ends_on']);

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'bar_percent' => $settings['bar_percent'], 'bar_days' => $settings['bar_days'],
            'checked_on' => $ends->subDays($settings['bar_days'])->toDateString(), 'rows' => $rows, 'close' => $close,
            'can_lift' => $this->sheets->holds($who, ResultsPermission::Moderate, $divisionId)];
    }

    /**
     * The examiners' board: each programme and year with its courses' progress.
     *
     * @return array<string, mixed>
     */
    public function board(User $who, string $divisionId, ?string $periodId): array
    {
        $this->sheets->enter($who, $divisionId);
        $period = $this->sheets->period($divisionId, $periodId);
        $out = [];
        foreach ($this->teaching->cohorts($divisionId, $period['id']) as $c) {
            $states = [];
            foreach ($c['offerings'] as $o) {
                $s = $this->sheets->sheet($o, 'main');
                $state = $s === null ? State::Entered->value : $s->state;
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
            $out[] = ['id' => $c['id'], 'label' => $c['label'], 'courses' => count($c['offerings']), 'states' => $states];
        }

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'cohorts' => $out, 'can' => $this->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function cohort(User $who, string $divisionId, ?string $periodId, string $cohortId): array
    {
        $this->sheets->enter($who, $divisionId);
        if (! $this->sheets->sees($who, $divisionId)) {
            throw new NotFoundHttpException;
        }
        $period = $this->sheets->period($divisionId, $periodId);
        $cohort = $this->actions->cohort($divisionId, $period['id'], $cohortId);
        [$programmeId, $year] = array_pad(explode(':', $cohortId), 2, '0');
        $offerings = array_values(array_filter($this->sheets->offerings($divisionId, $period['id']), fn (array $o): bool => in_array($o['id'], $cohort['offerings'], true)));
        $learners = [];
        foreach ($offerings as $o) {
            foreach ($this->teaching->students($o['id']) as $s) {
                $learners[$s['learner_id']] ??= $s;
            }
        }
        $students = [];
        foreach ($learners as $id => $s) {
            $file = $this->teaching->studentFile($id);
            if ($file === null || $file['programme_id'] !== $programmeId || $file['year_of_study'] !== (int) $year) {
                continue;
            }
            $sem = $this->standing->semester($divisionId, $period, $id, false);
            $byOffering = [];
            foreach ($sem['courses'] as $c) {
                $byOffering[$c['offering_id']] = ['mark' => $c['mark'], 'grade' => $c['grade'], 'result' => $c['result']];
            }
            $cumulative = $this->standing->history($id, false)['cumulative'];
            $students[] = ['learner_id' => $id, 'name' => $s['name'], 'number' => $s['number'], 'courses' => $byOffering, 'gpa' => $sem['gpa'], 'cumulative' => $cumulative,
                'decision' => $sem['decision'], 'supplementary' => $sem['supplementary'], 'deferred' => $sem['deferred']];
        }
        usort($students, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $sheets = [];
        foreach ($offerings as $o) {
            $s = $this->sheets->sheet($o['id'], 'main');
            $sheets[] = ['offering_id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'credits' => $o['credits'], 'sheet_id' => $s?->id, 'state' => $s === null ? State::Entered->value : $s->state];
        }
        $gpas = array_values(array_filter(array_column($students, 'gpa'), fn (?float $g): bool => $g !== null));

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'cohort' => ['id' => $cohort['id'], 'label' => $cohort['label']], 'courses' => $sheets, 'students' => $students,
            'summary' => ['students' => count($students), 'passed' => count(array_filter($students, fn (array $s): bool => $s['decision'] === 'pass')),
                'supplementary' => count(array_filter($students, fn (array $s): bool => $s['decision'] === 'supplementary')),
                'deferred' => count(array_filter($students, fn (array $s): bool => $s['decision'] === 'deferred')),
                'average_gpa' => $gpas === [] ? null : round(array_sum($gpas) / count($gpas), 2)],
            'waiting' => count(array_filter($sheets, fn (array $s): bool => $s['state'] === State::Moderated->value)),
            'can' => $this->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function release(User $who, string $divisionId, ?string $periodId): array
    {
        $this->sheets->enter($who, $divisionId);
        if (! $this->sheets->sees($who, $divisionId)) {
            throw new NotFoundHttpException;
        }
        $period = $this->sheets->period($divisionId, $periodId);
        $sittings = [];
        foreach (['main', 'supplementary'] as $sitting) {
            $states = [];
            $waiting = [];
            foreach ($this->sheets->offerings($divisionId, $period['id']) as $o) {
                $s = $this->sheets->sheet($o['id'], $sitting);
                if ($s === null && $sitting === 'supplementary') {
                    continue;
                }
                $state = $s === null ? State::Entered->value : $s->state;
                $states[$state] = ($states[$state] ?? 0) + 1;
                if (! in_array($state, [State::Approved->value, State::Released->value], true)) {
                    $waiting[] = ['code' => $o['code'], 'title' => $o['title'], 'state' => $state];
                }
            }
            $sittings[] = ['sitting' => $sitting, 'states' => $states, 'waiting' => $waiting];
        }
        $releases = [];
        foreach (DB::table('results_releases as r')->join('users as u', 'u.id', '=', 'r.released_by')->where('r.division_id', $divisionId)->where('r.period_id', $period['id'])
            ->orderByDesc('r.released_at')->get(['r.sitting', 'r.sheets', 'r.sms', 'r.released_at', 'u.name']) as $r) {
            $releases[] = ['sitting' => Row::text($r, 'sitting'), 'sheets' => Row::int($r, 'sheets'), 'sms' => (bool) $r->sms, 'by' => Row::text($r, 'name'),
                'at' => CarbonImmutable::parse(Row::text($r, 'released_at'))->toIso8601String()];
        }
        $supp = Sheet::query()->where('division_id', $divisionId)->where('period_id', $period['id'])->where('sitting', 'supplementary')->get();
        $candidates = 0;
        foreach ($supp as $s) {
            $candidates += count($this->sheets->students($this->sheets->find($divisionId, $s->offering_id), 'supplementary'));
        }

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'sittings' => $sittings, 'releases' => $releases,
            'supplementary' => ['sheets' => $supp->count(), 'candidates' => $candidates, 'exam_on' => $supp->first()?->exam_on?->toDateString()],
            'can' => $this->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function setup(User $who, string $divisionId): array
    {
        $this->sheets->enter($who, $divisionId);
        $current = $this->sheets->current($divisionId);

        return ['scale' => $current === null ? Scales::standard() : Scales::for($divisionId, $current['starts_on']), 'saved' => Scales::saved($divisionId),
            'standard' => Scales::standard(), 'settings' => Scales::settings($divisionId),
            'periods' => array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], $this->sheets->periods($divisionId)),
            'can' => $this->can($who, $divisionId)];
    }

    /** @return array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool} */
    public function can(User $who, string $divisionId): array
    {
        return ['setup' => $this->sheets->holds($who, ResultsPermission::Setup, $divisionId), 'release' => $this->sheets->holds($who, ResultsPermission::Release, $divisionId),
            'board' => $this->sheets->holds($who, ResultsPermission::Board, $divisionId), 'moderate' => $this->sheets->holds($who, ResultsPermission::Moderate, $divisionId),
            'enter' => $this->sheets->holds($who, ResultsPermission::Enter, $divisionId), 'view' => $this->sheets->sees($who, $divisionId),
            'award' => $this->sheets->holds($who, ResultsPermission::Award, $divisionId), 'transcripts' => $this->sheets->holds($who, ResultsPermission::Transcripts, $divisionId)];
    }
}
