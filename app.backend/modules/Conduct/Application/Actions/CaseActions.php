<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Conduct\Contracts\ConductAudit;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\ConductCase;
use Acadly\Modules\Conduct\Domain\Sanction;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\OutcomeId;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\StepId;
use Acadly\Modules\Conduct\Notifications\OutcomeDecided;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Following up a serious incident (D-092). Every change of state goes
 * through the journey engine. Notes and outcomes are added, never changed:
 * a later outcome for the same learner replaces the earlier one, and both stay.
 * The person who reported the incident cannot decide it.
 */
final class CaseActions
{
    public function __construct(
        private readonly JourneyEngine $engine,
        private readonly Access $access,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** The case, if the person may see it; not found otherwise. */
    public function find(User $who, string $id): ConductCase
    {
        $case = ConductCase::query()->find($id);

        if (! $case instanceof ConductCase || (! $this->access->allows($who, ConductPermission::View, $case->journeyScope()) && ! $this->access->allows($who, ConductPermission::FollowUp, $case->journeyScope()))) {
            throw new NotFoundHttpException;
        }

        return $case;
    }

    public function takeUp(User $by, string $id): void
    {
        $case = $this->find($by, $id);

        DB::transaction(function () use ($by, $case): void {
            $this->engine->perform($case, $by, StepId::TakeUp, OutcomeId::TakenUp);
            DB::table('conduct_cases')->where('id', $case->id)->update(['lead_by' => $by->id, 'updated_at' => $this->clock->now()]);
            $this->note($by, $case, 'Took it up.');
        });
    }

    public function addNote(User $by, string $id, string $text): void
    {
        $case = $this->find($by, $id);
        $this->access->check($by, ConductPermission::FollowUp, $case->journeyScope());
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages(['text' => ['Write the note.']]);
        }
        if (in_array($case->state, [State::Closed->value, State::Withdrawn->value], true)) {
            throw ValidationException::withMessages(['text' => ['The incident is closed.']]);
        }

        DB::transaction(function () use ($by, $case, $text): void {
            $this->note($by, $case, mb_substr($text, 0, 4000));
            $this->audit->record(ConductAudit::NoteAdded, subject: $case, actor: $this->audit->actorFor($by), at: $case->journeyScope());
        });
    }

    /** One learner's outcome. Given while the incident is looked into, by someone other than the reporter. */
    public function giveOutcome(User $by, string $id, string $learnerId, Sanction $sanction, ?string $startsOn, ?int $days, string $reason): void
    {
        $case = $this->find($by, $id);
        $this->access->check($by, ConductPermission::FollowUp, $case->journeyScope());
        $this->notTheReporter($by, $case);
        $reason = trim($reason);

        if ($case->state !== State::LookedInto->value) {
            throw ValidationException::withMessages(['outcome' => ['Take the incident up before giving an outcome.']]);
        }
        if (! DB::table('conduct_entry_learners')->where('entry_id', $case->entry_id)->where('learner_id', $learnerId)->exists()) {
            throw new NotFoundHttpException;
        }
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why.']]);
        }
        if ($sanction->hasDays() && ($startsOn === null || $days === null || $days < 1 || $days > 60)) {
            throw ValidationException::withMessages(['days' => ['Give the first day and from 1 to 60 days.']]);
        }

        DB::transaction(function () use ($by, $case, $learnerId, $sanction, $startsOn, $days, $reason): void {
            DB::table('conduct_outcomes')->insert(['id' => Ids::next(), 'case_id' => $case->id, 'learner_id' => $learnerId, 'outcome' => $sanction->value,
                'starts_on' => $sanction->hasDays() ? $startsOn : null, 'days' => $sanction->hasDays() ? $days : null, 'reason' => mb_substr($reason, 0, 2000),
                'decided_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ConductAudit::OutcomeGiven, subject: $case, actor: $this->audit->actorFor($by), at: $case->journeyScope(), reason: $reason,
                aboutPersonId: null, meta: ['learner' => $learnerId, 'outcome' => $sanction->value]);
        });
    }

    /** Decides the incident once every learner has an outcome, and tells each family. */
    public function decide(User $by, string $id): void
    {
        $case = $this->find($by, $id);
        $this->notTheReporter($by, $case);

        DB::transaction(function () use ($by, $case): void {
            $this->engine->perform($case, $by, StepId::Decide, OutcomeId::Decided);
            $this->note($by, $case, 'Decided.');

            foreach (self::latestOutcomes($case->id) as $learner => $o) {
                $this->notifier->send(new OutcomeDecided(Row::text($o, 'id'), $learner, $case->class_id, self::describe($o)), $by);
            }
        });
    }

    public function familyMet(User $by, string $id, bool $met, ?string $reason): void
    {
        $case = $this->find($by, $id);

        DB::transaction(function () use ($by, $case, $met, $reason): void {
            $this->engine->perform($case, $by, StepId::MeetFamily, $met ? OutcomeId::Met : OutcomeId::Told, $met ? [] : ['reason' => trim((string) $reason)]);
            $this->note($by, $case, $met ? 'Met the family.' : 'Told the family, not met: '.trim((string) $reason));
        });
    }

    public function close(User $by, string $id): void
    {
        $case = $this->find($by, $id);

        DB::transaction(function () use ($by, $case): void {
            $this->engine->perform($case, $by, StepId::Close, OutcomeId::Closed);
            $this->note($by, $case, 'Closed.');
        });
    }

    /** Withdraws the incident and its entry, with a reason: both stay in the history. */
    public function withdraw(User $by, string $id, string $reason): void
    {
        $case = $this->find($by, $id);

        DB::transaction(function () use ($by, $case, $reason): void {
            $this->engine->perform($case, $by, StepId::Withdraw, OutcomeId::Withdrawn, ['reason' => trim($reason)]);
            EntryActions::markWithdrawn($by, $case->entry_id, $reason, $this->clock);
            $this->note($by, $case, 'Withdrawn: '.trim($reason));
        });
    }

    /**
     * The outcome in force for each learner: the latest given.
     *
     * @return array<string, object>
     */
    public static function latestOutcomes(string $caseId): array
    {
        $out = [];

        foreach (DB::table('conduct_outcomes')->where('case_id', $caseId)->orderBy('created_at')->get(['id', 'learner_id', 'outcome', 'starts_on', 'days', 'reason', 'decided_by', 'created_at']) as $o) {
            $out[Row::text($o, 'learner_id')] = $o;
        }

        return $out;
    }

    /** "Suspension: 3 school days from 19 Nov". */
    public static function describe(object $outcome): string
    {
        $sanction = Sanction::from(Row::text($outcome, 'outcome'));
        $starts = Row::maybeText($outcome, 'starts_on');

        return $sanction->label().($sanction->hasDays() && $starts !== null
            ? ': '.Row::int($outcome, 'days').' school '.(Row::int($outcome, 'days') === 1 ? 'day' : 'days').' from '.CarbonImmutable::parse($starts)->format('j M')
            : '');
    }

    private function notTheReporter(User $by, ConductCase $case): void
    {
        if (DB::table('conduct_entries')->where('id', $case->entry_id)->value('recorded_by') === $by->id) {
            throw ValidationException::withMessages(['outcome' => ['The person who reported the incident cannot decide it.']]);
        }
    }

    private function note(User $by, ConductCase $case, string $text): void
    {
        DB::table('conduct_case_notes')->insert(['id' => Ids::next(), 'case_id' => $case->id, 'text' => $text, 'written_by' => $by->id, 'created_at' => $this->clock->now()]);
    }
}
