<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Programmes\Contracts\StudentProgress;
use Acadly\Modules\Results\Application\Awards;
use Acadly\Modules\Results\Application\Progression;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Contracts\ResultsAudit;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\AwardList;
use Acadly\Modules\Results\Domain\ProgressionList;
use Acadly\Modules\Results\Journeys\Award\OutcomeId as AwardOutcome;
use Acadly\Modules\Results\Journeys\Award\State as AwardState;
use Acadly\Modules\Results\Journeys\Award\StepId as AwardStep;
use Acadly\Modules\Results\Journeys\Progression\OutcomeId;
use Acadly\Modules\Results\Journeys\Progression\State;
use Acadly\Modules\Results\Journeys\Progression\StepId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The board's end-of-year work (D-106): choosing a student's progression
 * with a reason, deciding a programme and year's list, applying it through
 * Programmes, and the graduation list from approval to conferral. Choices,
 * decisions and awards are rows, append-only.
 */
final class ProgressionActions
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Progression $progression,
        private readonly Awards $awards,
        private readonly StudentProgress $progress,
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** The board chooses one student's decision, with a reason. */
    public function choose(User $by, string $divisionId, string $programmeId, int $year, string $learnerId, string $decision, string $reason): void
    {
        $this->allowed($by, $divisionId, ResultsPermission::Board);
        if (! in_array($decision, Progression::CHOICES, true)) {
            throw ValidationException::withMessages(['decision' => ['Choose a decision from the list.']]);
        }
        $list = $this->open($by, $divisionId, $programmeId, $year);
        if ($list->state !== State::WorkedOut->value) {
            throw ValidationException::withMessages(['decision' => ['These decisions are made. Reopen them to change one.']]);
        }
        $row = array_values(array_filter($this->progression->rows($list), fn (array $r): bool => $r['learner_id'] === $learnerId))[0] ?? throw new NotFoundHttpException;
        if ($decision === 'continue' && $row['fallback'] === null) {
            throw ValidationException::withMessages(['decision' => ['The results of this student are not complete yet.']]);
        }
        DB::transaction(function () use ($by, $list, $learnerId, $decision, $reason): void {
            DB::table('results_progression_choices')->insert(['id' => Ids::next(), 'list_id' => $list->id, 'learner_id' => $learnerId, 'decision' => $decision,
                'reason' => $reason, 'by_user_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::ProgressionChosen, subject: $list, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $list->division_id),
                reason: $reason, aboutPersonId: $learnerId, meta: ['decision' => $decision]);
        });
    }

    /** The board decides the list: every student's decision is kept as a row. */
    public function decide(User $by, string $divisionId, string $programmeId, int $year): void
    {
        $this->allowed($by, $divisionId, ResultsPermission::Board);
        $list = $this->open($by, $divisionId, $programmeId, $year);
        DB::transaction(function () use ($by, $list): void {
            $rows = $this->progression->rows($list);
            $this->engine->perform($list, $by, StepId::Decide, OutcomeId::Decided);
            $now = $this->clock->now();
            foreach ($rows as $r) {
                $carried = in_array($r['decision'], ['carry', 'repeat_year'], true) ? $r['failed'] : [];
                DB::table('results_progression_decisions')->insert(['id' => Ids::next(), 'list_id' => $list->id, 'learner_id' => $r['learner_id'],
                    'decision' => $r['decision'] ?? 'repeat_year', 'carried' => json_encode($carried), 'cgpa' => $r['cgpa'] === null ? null : round($r['cgpa'], 2),
                    'failed_credits' => $r['failed_credits'], 'standing' => $r['standing'], 'reason' => $r['choice']['reason'] ?? null, 'created_at' => $now]);
            }
        });
    }

    public function reopen(User $by, string $divisionId, string $listId, string $reason): void
    {
        $list = $this->list($by, $divisionId, $listId, ResultsPermission::Board);
        DB::transaction(fn () => $this->engine->perform($list, $by, StepId::Reopen, OutcomeId::Reopened, ['reason' => $reason]));
    }

    /** The registrar applies the decided list: each student's year or status changes in Programmes, with the courses carried. */
    public function apply(User $by, string $divisionId, string $listId): int
    {
        $list = $this->list($by, $divisionId, $listId, ResultsPermission::Release);
        $period = $this->progression->year($divisionId)['last'];
        $decisions = [];
        foreach (DB::table('results_progression_decisions')->where('list_id', $list->id)->orderBy('created_at')->get() as $d) {
            $decisions[is_string($d->learner_id) ? $d->learner_id : ''] = $d;
        }
        DB::transaction(function () use ($by, $list, $decisions, $period): void {
            $this->engine->perform($list, $by, StepId::Apply, OutcomeId::Applied);
            foreach ($decisions as $learnerId => $d) {
                $this->progress->apply($by, $learnerId, is_string($d->decision) ? $d->decision : 'repeat_year',
                    array_column(Progression::carried($d), 'course_id'), "Examiners' board: end of {$period['name']}.");
            }
            $this->audit->record(ResultsAudit::ProgressionApplied, subject: $list, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $list->division_id),
                meta: ['students' => count($decisions)]);
        });

        return count($decisions);
    }

    /** The examiners' board approves a programme's graduation list: each student's award is kept as a row. */
    public function approveAwards(User $by, string $divisionId, string $programmeId): void
    {
        $this->allowed($by, $divisionId, ResultsPermission::Board);
        $this->progression->programme($divisionId, $programmeId);
        $list = $this->awards->find($divisionId, $programmeId) ?? $this->newAwardList($by, $divisionId, $programmeId);
        $rows = $this->awards->rows($divisionId, $programmeId, $list);
        if ($rows === []) {
            throw ValidationException::withMessages(['programme' => ['No student is in the final year of this programme.']]);
        }
        DB::transaction(function () use ($by, $list, $rows): void {
            $this->engine->perform($list, $by, AwardStep::Approve, AwardOutcome::Approved);
            $now = $this->clock->now();
            foreach ($rows as $r) {
                DB::table('results_awards')->insert(['id' => Ids::next(), 'list_id' => $list->id, 'learner_id' => $r['learner_id'], 'cgpa' => $r['cgpa'] === null ? null : round($r['cgpa'], 2),
                    'credits' => $r['credits'], 'class' => $r['class'], 'complete' => $r['complete'], 'missing' => json_encode($r['missing']), 'created_at' => $now]);
            }
        });
    }

    /** The academic board confirms the list, or returns it with a reason. */
    public function confirmAwards(User $by, string $divisionId, string $listId, bool $confirm, ?string $reason): void
    {
        $list = $this->awardList($by, $divisionId, $listId, ResultsPermission::Award);
        DB::transaction(fn () => $this->engine->perform($list, $by, AwardStep::Confirm, $confirm ? AwardOutcome::Confirmed : AwardOutcome::Returned, $confirm ? [] : ['reason' => $reason]));
    }

    public function graduationDay(User $by, string $divisionId, string $listId, string $day): void
    {
        $list = $this->awardList($by, $divisionId, $listId, ResultsPermission::Release);
        if ($list->state === AwardState::Conferred->value) {
            throw ValidationException::withMessages(['graduation_on' => ['The awards are conferred.']]);
        }
        $list->forceFill(['graduation_on' => $day])->save();
    }

    /** On the graduation day the awards are conferred: each completed student's programme ends as completed. */
    public function confer(User $by, string $divisionId, string $listId): int
    {
        $list = $this->awardList($by, $divisionId, $listId, ResultsPermission::Release);
        if ($list->graduation_on === null) {
            throw ValidationException::withMessages(['graduation_on' => ['Set the graduation day first.']]);
        }
        $rows = array_values(array_filter($this->awards->rows($divisionId, $list->programme_id, $list), fn (array $r): bool => $r['complete']));
        $programme = $this->progression->programme($divisionId, $list->programme_id);
        DB::transaction(function () use ($by, $list, $rows, $programme): void {
            $this->engine->perform($list, $by, AwardStep::Confer, AwardOutcome::Conferred);
            foreach ($rows as $r) {
                $this->progress->apply($by, $r['learner_id'], 'completed', [], "Awarded: {$programme['name']}".($r['class'] !== null ? ", {$r['class']}" : '').'.');
            }
            $this->audit->record(ResultsAudit::AwardsConferred, subject: $list, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $list->division_id),
                meta: ['students' => count($rows)]);
        });

        return count($rows);
    }

    private function open(User $by, string $divisionId, string $programmeId, int $year): ProgressionList
    {
        $this->progression->programme($divisionId, $programmeId);
        $list = $this->progression->find($divisionId, $programmeId, $year);
        if ($list !== null) {
            return $list;
        }
        $list = new ProgressionList;
        $list->forceFill(['division_id' => $divisionId, 'period_id' => $this->progression->year($divisionId)['last']['id'], 'programme_id' => $programmeId,
            'year_of_study' => $year, 'state' => State::WorkedOut->value, 'journey_ref' => (string) $this->blueprints->newest('results.progression'), 'created_by' => $by->id])->save();

        return $list;
    }

    private function newAwardList(User $by, string $divisionId, string $programmeId): AwardList
    {
        $list = new AwardList;
        $list->forceFill(['division_id' => $divisionId, 'period_id' => $this->progression->year($divisionId)['last']['id'], 'programme_id' => $programmeId,
            'state' => AwardState::WorkedOut->value, 'journey_ref' => (string) $this->blueprints->newest('results.award'), 'created_by' => $by->id])->save();

        return $list;
    }

    private function list(User $by, string $divisionId, string $listId, ResultsPermission $p): ProgressionList
    {
        $this->allowed($by, $divisionId, $p);

        return ProgressionList::query()->where('id', $listId)->where('division_id', $divisionId)->first() ?? throw new NotFoundHttpException;
    }

    private function awardList(User $by, string $divisionId, string $listId, ResultsPermission $p): AwardList
    {
        $this->allowed($by, $divisionId, $p);

        return AwardList::query()->where('id', $listId)->where('division_id', $divisionId)->first() ?? throw new NotFoundHttpException;
    }

    private function allowed(User $by, string $divisionId, ResultsPermission $p): void
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, $p, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
    }
}
