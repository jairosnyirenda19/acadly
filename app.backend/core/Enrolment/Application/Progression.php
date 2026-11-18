<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Core\Enrolment\Journeys\Progression\State;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the year-end run shows (D-088): each level group's run, every
 * learner's outcome and class next year, the columns and checks other
 * modules give, and the learners another group hands on for intake.
 */
final class Progression
{
    /** @param  iterable<YearEndCheck>  $checks */
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly Directory $learners,
        private readonly iterable $checks = [],
    ) {}

    /**
     * Each level group the viewer may run, with its run's state and the intake waiting for it.
     *
     * @return array{from_year: array{id: string, name: string}|null, to_year: array{id: string, name: string, starts_on: string}|null, groups: list<array{id: string, name: string, state: string|null, intake: int}>}
     */
    public function index(User $viewer): array
    {
        $from = $this->calendar->currentYear();
        $to = $this->calendar->nextYear();
        $groups = [];

        foreach ($this->school->divisionViews() as $division) {
            if (! $this->access->allows($viewer, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::Division, $division->id))) {
                continue;
            }

            $state = $from !== null ? DB::table('progression_runs')->where('division_id', $division->id)->where('from_year_id', $from['id'])->value('state') : null;
            $groups[] = ['id' => $division->id, 'name' => $division->name, 'state' => is_string($state) ? $state : null,
                'intake' => DB::table('progression_decisions')->where('to_division_id', $division->id)->where('state', State::AwaitingIntake->value)->count()];
        }

        // Someone who may run no level group's year end is refused, as everywhere else.
        if ($groups === []) {
            throw new AuthorizationException;
        }

        return [
            'from_year' => $from !== null ? ['id' => $from['id'], 'name' => $from['name']] : null,
            'to_year' => $to !== null ? ['id' => $to['id'], 'name' => $to['name'], 'starts_on' => $to['starts_on']] : null,
            'groups' => $groups,
        ];
    }

    /**
     * One level group's run: the outcomes, the classes to choose from, the
     * columns, the checks, and the intake handed on to this group.
     *
     * @return array<string, mixed>
     */
    public function show(User $viewer, string $divisionId): array
    {
        $division = $this->school->division($divisionId) ?? throw new NotFoundHttpException;
        $this->access->check($viewer, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::Division, $division->id));
        $from = $this->calendar->currentYear();
        $to = $this->calendar->nextYear();
        $run = $from !== null ? DB::table('progression_runs')->where('division_id', $division->id)->where('from_year_id', $from['id'])->first() : null;
        $decisions = $run !== null ? DB::table('progression_decisions')->where('run_id', $run->id)->get()->all() : [];
        $intake = DB::table('progression_decisions')->where('to_division_id', $division->id)->whereIn('state', [State::AwaitingIntake->value])->get()->all();
        $all = [...$decisions, ...$intake];
        $names = $this->learners->names(array_values(array_unique(array_map(fn (object $d): string => Row::text($d, 'learner_id'), $all))));
        $classIds = array_values(array_unique(array_filter([...array_map(fn (object $d): string => Row::text($d, 'from_class_id'), $all),
            ...array_map(fn (object $d): ?string => Row::maybeText($d, 'to_class_id'), $all)], 'is_string')));
        $classNames = $this->calendar->classNames($classIds);
        $label = fn (?string $id): ?string => $id !== null ? ($classNames[$id]['name'] ?? null) : null;
        $row = fn (object $d): array => [
            'id' => Row::text($d, 'id'),
            'learner_id' => Row::text($d, 'learner_id'),
            'learner' => $names[Row::text($d, 'learner_id')] ?? '',
            'class_id' => Row::text($d, 'from_class_id'),
            'class' => $label(Row::text($d, 'from_class_id')) ?? '',
            'level' => Row::text($d, 'from_level'),
            'outcome' => Row::text($d, 'outcome'),
            'to_class_id' => Row::maybeText($d, 'to_class_id'),
            'to_class' => $label(Row::maybeText($d, 'to_class_id')),
            'reason' => Row::maybeText($d, 'reason'),
            'changed' => Row::maybeText($d, 'changed_by') !== null,
            'state' => Row::text($d, 'state'),
        ];
        $rows = array_map($row, $decisions);
        usort($rows, fn (array $a, array $b): int => [$a['class'], $a['learner']] <=> [$b['class'], $b['learner']]);
        $columns = [];

        if ($from !== null && $decisions !== []) {
            $learnerIds = array_values(array_map(fn (object $d): string => Row::text($d, 'learner_id'), $decisions));

            foreach ($this->checks as $check) {
                $column = $check->column($from['id'], $learnerIds);

                if ($column !== null) {
                    $columns[] = $column;
                }
            }
        }

        $count = fn (string $outcome): int => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === $outcome && $r['state'] !== State::Cancelled->value));

        return [
            'division' => ['id' => $division->id, 'name' => $division->name, 'levels' => $division->profile->levels],
            'from_year' => $from !== null ? ['id' => $from['id'], 'name' => $from['name'], 'ends_on' => $from['ends_on']] : null,
            'to_year' => $to !== null ? ['id' => $to['id'], 'name' => $to['name'], 'starts_on' => $to['starts_on']] : null,
            'run' => $run !== null ? ['id' => Row::text($run, 'id'), 'state' => Row::text($run, 'state'),
                'committed_at' => Row::maybeText($run, 'committed_at'), 'committed_by' => $this->userName(Row::maybeText($run, 'committed_by'))] : null,
            'decisions' => $rows,
            'columns' => $columns,
            'targets' => $to !== null ? $this->targets($division, $to['id']) : [],
            'checks' => $run !== null ? $this->problems(Row::text($run, 'id')) : [],
            'totals' => ['learners' => count($rows), 'promoted' => $count('promoted'), 'repeated' => $count('repeated'), 'progressed' => $count('progressed'),
                'completed' => $count('completed'), 'left' => $count('left'), 'to_decide' => count(array_filter($rows, fn (array $r): bool => $r['state'] === State::Proposed->value))],
            'intake' => array_map($row, $intake),
        ];
    }

    /**
     * What stops the commit: the checks of other modules, and outcomes that
     * still need a class.
     *
     * @return list<string>
     */
    public function problems(string $runId): array
    {
        $run = DB::table('progression_runs')->where('id', $runId)->first() ?? throw new NotFoundHttpException;
        $open = DB::table('progression_decisions')->where('run_id', $runId)->where('state', State::Proposed->value)->get(['outcome', 'to_class_id', 'learner_id']);
        $classIds = array_values(array_unique(DB::table('progression_decisions')->where('run_id', $runId)->pluck('from_class_id')->filter(fn ($v): bool => is_string($v))->all()));
        $problems = [];

        foreach ($this->checks as $check) {
            array_push($problems, ...$check->problems(Row::text($run, 'from_year_id'), $classIds));
        }

        $missing = count(array_filter($open->all(), fn (object $d): bool => in_array(Row::text($d, 'outcome'), ['promoted', 'repeated', 'progressed'], true) && Row::maybeText($d, 'to_class_id') === null));

        if ($missing > 0) {
            $problems[] = $missing === 1 ? 'One learner has no class for next year.' : "{$missing} learners have no class for next year.";
        }

        return $problems;
    }

    /** The stream of a class, from its name: "Standard 4 A" is stream "A" of Standard 4. */
    public function streamOf(string $className, DivisionView $division, string $level): string
    {
        $label = '';

        foreach ($division->profile->levels as $l) {
            if ($l['code'] === $level) {
                $label = $l['label'];
            }
        }

        return $label !== '' && str_starts_with($className, $label) ? trim(substr($className, strlen($label))) : '';
    }

    /**
     * The class next year for a level at a campus: the one with the same stream, or the first.
     *
     * @param  list<array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $next
     */
    public function classFor(array $next, DivisionView $division, string $level, string $campus, string $stream): ?string
    {
        $fits = array_values(array_filter($next, fn (array $c): bool => $c['division_id'] === $division->id && $c['level_code'] === $level && $c['campus_id'] === $campus));

        foreach ($fits as $c) {
            if ($stream !== '' && $this->streamOf($c['name'], $division, $level) === $stream) {
                return $c['id'];
            }
        }

        return $fits[0]['id'] ?? null;
    }

    /** The level group after this one in the school's order that is taught at the campus, or null. */
    public function following(DivisionView $division, string $campus): ?DivisionView
    {
        $after = false;

        foreach ($this->school->divisionViews() as $d) {
            if ($after && $this->school->offers($d->id, $campus)) {
                return $d;
            }

            $after = $after || $d->id === $division->id;
        }

        return null;
    }

    /**
     * The classes next year an outcome may go to: this group's, and the first level of the groups after it.
     *
     * @return list<array{id: string, name: string, division_id: string, campus_id: string, level_code: string}>
     */
    private function targets(DivisionView $division, string $toYearId): array
    {
        $later = false;
        $firsts = [];

        foreach ($this->school->divisionViews() as $d) {
            if ($later) {
                $firsts[$d->id] = $d->profile->levels[0]['code'] ?? '';
            }

            $later = $later || $d->id === $division->id;
        }

        return array_values(array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'division_id' => $c['division_id'], 'campus_id' => $c['campus_id'], 'level_code' => $c['level_code']],
            array_filter($this->calendar->classesInYear($toYearId), fn (array $c): bool => $c['division_id'] === $division->id
                || (isset($firsts[$c['division_id']]) && $firsts[$c['division_id']] === $c['level_code']))));
    }

    private function userName(?string $id): ?string
    {
        $name = $id !== null ? DB::table('users')->where('id', $id)->value('name') : null;

        return is_string($name) ? $name : null;
    }
}
