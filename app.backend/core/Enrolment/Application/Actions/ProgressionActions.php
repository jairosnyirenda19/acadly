<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Progression;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\ProgressionDecision;
use Acadly\Core\Enrolment\Journeys\Progression\OutcomeId;
use Acadly\Core\Enrolment\Journeys\Progression\State;
use Acadly\Core\Enrolment\Journeys\Progression\StepId;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The year-end run of one level group (09 §4.2, D-088): opened with an
 * outcome proposed for every learner, the exceptions changed by the office,
 * committed once the checks pass, the intake confirmed by the receiving
 * level group, and applied on the first day of the next school year.
 */
final class ProgressionActions
{
    public const array REASON_NEEDED = ['repeated', 'left'];

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly Progression $progression,
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Opens the run of a level group: every learner in it this year, with an outcome proposed. */
    public function open(User $by, string $divisionId): string
    {
        $division = $this->division($by, $divisionId);
        $from = $this->calendar->currentYear() ?? throw ValidationException::withMessages(['year' => ['Set up this school year first.']]);
        $to = $this->calendar->nextYear() ?? throw ValidationException::withMessages(['year' => ['Set up the next school year first: Settings › Next school year.']]);

        $existing = DB::table('progression_runs')->where('division_id', $division->id)->where('from_year_id', $from['id'])->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $next = $this->calendar->classesInYear($to['id']);
        $classes = [];

        foreach ($this->calendar->classesInYear($from['id']) as $class) {
            $classes[$class['id']] = $class;
        }

        $places = DB::table('placements as p')->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->where('p.year_id', $from['id'])->where('p.division_id', $division->id)->whereNull('p.ends_on')->where('e.status', 'active')
            ->get(['p.id', 'p.learner_id', 'p.enrolment_id', 'p.class_id', 'p.campus_id', 'p.level_code']);
        $ref = (string) $this->blueprints->newest('enrolment.progression');

        return DB::transaction(function () use ($by, $division, $from, $to, $next, $classes, $places, $ref): string {
            $run = Ids::next();
            $now = $this->clock->now();
            DB::table('progression_runs')->insert(['id' => $run, 'division_id' => $division->id, 'from_year_id' => $from['id'], 'to_year_id' => $to['id'],
                'state' => 'open', 'opened_by' => $by->id, 'opened_at' => $now]);

            foreach ($places as $p) {
                $level = Row::text($p, 'level_code');
                $campus = Row::text($p, 'campus_id');
                $stream = $this->progression->streamOf($classes[Row::text($p, 'class_id')]['name'] ?? '', $division, $level);
                [$outcome, $toClass, $toDivision] = $this->proposal($division, $level, $campus, $stream, $next);
                ProgressionDecision::query()->create([
                    'run_id' => $run, 'learner_id' => Row::text($p, 'learner_id'), 'enrolment_id' => Row::text($p, 'enrolment_id'),
                    'from_placement_id' => Row::text($p, 'id'), 'from_class_id' => Row::text($p, 'class_id'), 'from_division_id' => $division->id,
                    'campus_id' => $campus, 'from_level' => $level, 'outcome' => $outcome, 'to_class_id' => $toClass, 'to_division_id' => $toDivision,
                    'state' => State::Proposed->value, 'journey_ref' => $ref,
                ]);
            }

            $this->audit->record(EnrolmentAudit::ProgressionOpened, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $division->id),
                meta: ['learners' => count($places), 'to_year' => $to['name']]);

            return $run;
        });
    }

    /** Changes a proposed outcome: promoted, repeat, completed, leave or to the next level group, and the class next year. */
    public function change(User $by, string $decisionId, string $outcome, ?string $toClassId, ?string $reason): void
    {
        $decision = $this->decision($by, $decisionId);

        if ($decision->state !== State::Proposed->value) {
            throw ValidationException::withMessages(['outcome' => ['This outcome has been decided. Cancel it to decide again.']]);
        }

        $reason = trim((string) $reason);
        [$toClass, $toDivision] = $this->target($decision, $outcome, $toClassId, $reason);

        DB::transaction(function () use ($by, $decision, $outcome, $toClass, $toDivision, $reason): void {
            $decision->forceFill(['outcome' => $outcome, 'to_class_id' => $toClass, 'to_division_id' => $toDivision,
                'reason' => $reason !== '' ? $reason : null, 'changed_by' => $by->id])->save();
            $this->audit->record(EnrolmentAudit::ProgressionChanged, subject: $decision, actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Division, $decision->from_division_id), meta: ['outcome' => $outcome]);
        });
    }

    /**
     * Commits the run: every proposed outcome is decided. Refused while a
     * check stands. A second commit decides only what is proposed again.
     */
    public function commit(User $by, string $runId): void
    {
        $run = DB::table('progression_runs')->where('id', $runId)->first() ?? throw new NotFoundHttpException;
        $this->division($by, Row::text($run, 'division_id'));
        $problems = $this->progression->problems($runId);

        if ($problems !== []) {
            throw ValidationException::withMessages(['commit' => $problems]);
        }

        DB::transaction(function () use ($by, $run, $runId): void {
            $decided = 0;

            foreach (ProgressionDecision::query()->where('run_id', $runId)->where('state', State::Proposed->value)->lockForUpdate()->get() as $decision) {
                $this->engine->perform($decision, $by, StepId::Decide, OutcomeId::from($decision->outcome), $decision->reason !== null ? ['reason' => $decision->reason] : []);
                $decided++;
            }

            DB::table('progression_runs')->where('id', $runId)->update(['state' => 'committed', 'committed_by' => $by->id, 'committed_at' => $this->clock->now()]);
            $this->audit->record(EnrolmentAudit::ProgressionCommitted, actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Division, Row::text($run, 'division_id')), meta: ['decided' => $decided]);
        });

        $this->applyDue($runId);
    }

    /** The receiving level group confirms a learner, in a class it chooses, or turns them back with a reason. */
    public function intake(User $by, string $decisionId, bool $accept, ?string $toClassId, ?string $reason): void
    {
        $decision = ProgressionDecision::query()->find($decisionId) ?? throw new NotFoundHttpException;

        if ($decision->state !== State::AwaitingIntake->value || $decision->to_division_id === null) {
            throw ValidationException::withMessages(['intake' => ['This learner is not waiting for an intake.']]);
        }

        $this->division($by, $decision->to_division_id);

        DB::transaction(function () use ($by, $decision, $accept, $toClassId, $reason): void {
            if ($accept) {
                if ($toClassId !== null && $toClassId !== $decision->to_class_id) {
                    [$toClass] = $this->target($decision, 'progressed', $toClassId, '');
                    $decision->forceFill(['to_class_id' => $toClass])->save();
                }

                $this->engine->perform($decision, $by, StepId::ConfirmIntake, OutcomeId::Accepted);

                return;
            }

            $this->engine->perform($decision, $by, StepId::ConfirmIntake, OutcomeId::TurnedBack, ['reason' => trim((string) $reason)]);
            $decision->refresh()->forceFill(['reason' => 'Turned back: '.trim((string) $reason)])->save();
        });

        $this->applyDue($decision->run_id);
    }

    /**
     * Applies every decided outcome whose next school year has started, as
     * the person who committed its run (the scheduled first-day step).
     */
    public function applyDue(?string $runId = null): int
    {
        $today = $this->clock->today();
        $due = DB::table('progression_decisions as d')->join('progression_runs as r', 'r.id', '=', 'd.run_id')->join('academic_years as y', 'y.id', '=', 'r.to_year_id')
            ->where('d.state', State::Decided->value)->where('y.starts_on', '<=', $today)->whereNotNull('r.committed_by')
            ->when($runId !== null, fn ($q) => $q->where('r.id', $runId))
            ->get(['d.id', 'r.committed_by']);
        $applied = 0;

        foreach ($due as $row) {
            $who = User::query()->find(Row::text($row, 'committed_by'));
            $decision = ProgressionDecision::query()->find(Row::text($row, 'id'));

            if ($who instanceof User && $decision instanceof ProgressionDecision) {
                DB::transaction(fn () => $this->engine->perform($decision, $who, StepId::TakeEffect, OutcomeId::Applied));
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * The outcome proposed for a learner: the next level of the group, or at
     * its last level the first level of the next group at the same campus, or
     * completed. The class keeps the stream where the next year has it.
     *
     * @param  list<array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $next
     * @return array{string, string|null, string|null}
     */
    private function proposal(DivisionView $division, string $level, string $campus, string $stream, array $next): array
    {
        $codes = array_column($division->profile->levels, 'code');
        $at = array_search($level, $codes, true);

        if ($at !== false && isset($codes[$at + 1])) {
            return ['promoted', $this->progression->classFor($next, $division, $codes[$at + 1], $campus, $stream), null];
        }

        $following = $this->progression->following($division, $campus);

        if ($following !== null) {
            $first = $following->profile->levels[0]['code'] ?? '';

            return ['progressed', $this->progression->classFor($next, $following, $first, $campus, $stream), $following->id];
        }

        return ['completed', null, null];
    }

    /**
     * The class and level group next year for an outcome, once it fits.
     *
     * @return array{string|null, string|null}
     */
    private function target(ProgressionDecision $decision, string $outcome, ?string $toClassId, string $reason): array
    {
        $division = $this->school->division($decision->from_division_id) ?? throw new NotFoundHttpException;
        $codes = array_column($division->profile->levels, 'code');
        $at = array_search($decision->from_level, $codes, true);
        $last = $at === false || ! isset($codes[$at + 1]);

        if (OutcomeId::tryFrom($outcome) === null || ! in_array($outcome, ['promoted', 'repeated', 'progressed', 'completed', 'left'], true)) {
            throw ValidationException::withMessages(['outcome' => ['Choose an outcome.']]);
        }

        if (in_array($outcome, self::REASON_NEEDED, true) && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why, up to 255 characters.']]);
        }

        if (mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => ['A reason can be up to 255 characters.']]);
        }

        if (in_array($outcome, ['completed', 'left'], true)) {
            if ($outcome === 'completed' && ! $last) {
                throw ValidationException::withMessages(['outcome' => ['Only a learner at the last level of the group completes.']]);
            }

            return [null, null];
        }

        if ($outcome === 'promoted' && $last) {
            throw ValidationException::withMessages(['outcome' => ['This is the last level of the group: the learner goes on to the next group, completes or leaves.']]);
        }

        $to = $this->calendar->nextYear() ?? throw ValidationException::withMessages(['year' => ['Set up the next school year first.']]);
        $class = $toClassId !== null ? $this->calendar->classInForce($toClassId) : null;

        if ($class === null || $class['year_id'] !== $to['id']) {
            throw ValidationException::withMessages(['to_class_id' => ['Choose a class of '.$to['name'].'.']]);
        }

        $want = match ($outcome) {
            'promoted' => $class['division_id'] === $division->id && $class['level_code'] === ($codes[(int) $at + 1] ?? null),
            'repeated' => $class['division_id'] === $division->id && $class['level_code'] === $decision->from_level,
            default => $last && $class['division_id'] !== $division->id
                && $class['level_code'] === ($this->school->division($class['division_id'])?->profile->levels[0]['code'] ?? null),
        };

        if (! $want) {
            throw ValidationException::withMessages(['to_class_id' => ['This class does not fit the outcome.']]);
        }

        return [$class['id'], $outcome === 'progressed' ? $class['division_id'] : null];
    }

    private function decision(User $by, string $id): ProgressionDecision
    {
        $decision = ProgressionDecision::query()->find($id) ?? throw new NotFoundHttpException;
        $this->division($by, $decision->from_division_id);

        return $decision;
    }

    private function division(User $by, string $divisionId): DivisionView
    {
        $division = $this->school->division($divisionId) ?? throw new NotFoundHttpException;
        $this->access->check($by, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::Division, $division->id));

        return $division;
    }
}
