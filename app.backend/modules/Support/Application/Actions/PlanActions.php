<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Support\Application\Signals;
use Acadly\Modules\Support\Application\SupportReach;
use Acadly\Modules\Support\Contracts\SupportAudit;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Domain\SupportPlan;
use Acadly\Modules\Support\Journeys\Plan\OutcomeId;
use Acadly\Modules\Support\Journeys\Plan\State;
use Acadly\Modules\Support\Journeys\Plan\StepId;
use Acadly\Modules\Support\Notifications\PlanNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Support plans (D-093). Every change of state goes through the journey
 * engine; goals, actions and reviews are added, never changed. The family
 * is told when a plan opens and closes, unless it is confidential.
 */
final class PlanActions
{
    public const array GOAL_STATES = ['met', 'partly', 'not_yet'];

    public function __construct(
        private readonly SupportReach $reach,
        private readonly Signals $signals,
        private readonly Access $access,
        private readonly Blueprints $blueprints,
        private readonly JourneyEngine $engine,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param list<array{text: string, measure: string|null, by_when: string|null}> $goals */
    public function open(User $by, string $learnerId, string $title, string $concern, ?string $leadId, bool $confidential, ?bool $tell, ?string $firstReview, array $goals): SupportPlan
    {
        $at = $this->reach->learner($by, $learnerId, SupportPermission::Lead);
        $title = trim($title);
        $concern = trim($concern);
        $leadId ??= $by->id;

        if ($title === '' || $concern === '') {
            throw ValidationException::withMessages(['title' => ['Give the plan a title and say what the concern is.']]);
        }
        if (! $this->access->allows(User::query()->find($leadId), SupportPermission::Lead, $at)) {
            throw ValidationException::withMessages(['lead_id' => ['The person who leads the plan must be able to lead support for this learner.']]);
        }
        if ($confidential && ! $this->access->allows($by, SupportPermission::Confidential, $at)) {
            throw ValidationException::withMessages(['confidential' => ['Only someone who may see confidential plans can open one.']]);
        }

        $values = Signals::values();
        $days = max(1, $values['plan.review_days'] ?? 14);
        $tell = ! $confidential && ($tell ?? (($values['plan.tell_family'] ?? 1) === 1));
        $review = $firstReview ?? CarbonImmutable::parse($this->clock->today())->addDays($days)->toDateString();
        $why = array_map(fn (array $r): string => $r['label'].': '.$r['text'], $this->signals->of([$learnerId])[$learnerId] ?? []);

        return DB::transaction(function () use ($by, $learnerId, $at, $title, $concern, $leadId, $confidential, $tell, $days, $review, $why, $goals): SupportPlan {
            $plan = SupportPlan::query()->create([
                'learner_id' => $learnerId, 'class_id' => (string) $at->id, 'title' => mb_substr($title, 0, 120), 'concern' => mb_substr($concern, 0, 4000),
                'signals' => $why === [] ? null : implode("\n", $why), 'lead_by' => $leadId, 'confidential' => $confidential, 'tell_family' => $tell,
                'review_days' => $days, 'next_review_on' => $review, 'state' => State::Raised->value,
                'journey_ref' => (string) $this->blueprints->newest('support.plan'), 'opened_by' => $by->id,
            ]);

            foreach ($goals as $goal) {
                $this->insertGoal($by, $plan, $goal);
            }

            $this->audit->record(SupportAudit::PlanOpened, subject: $plan, actor: $this->audit->actorFor($by), at: $at,
                meta: ['learner' => $learnerId, 'confidential' => $confidential, 'goals' => count($goals)]);

            if ($tell) {
                $this->notifier->send(new PlanNotice($plan->id, $learnerId, $plan->class_id, 'opened', $plan->title, self::name($leadId)), $by);
            }

            return $plan;
        });
    }

    /** @param array{text: string, measure: string|null, by_when: string|null} $goal */
    public function addGoal(User $by, string $planId, array $goal): void
    {
        $plan = $this->leads($by, $planId);

        DB::transaction(function () use ($by, $plan, $goal): void {
            $this->insertGoal($by, $plan, $goal);
            $this->audit->record(SupportAudit::PlanChanged, subject: $plan, actor: $this->audit->actorFor($by), at: $plan->journeyScope(), meta: ['added' => 'goal']);
        });
    }

    public function addAction(User $by, string $planId, string $who, string $what, ?string $when): void
    {
        $plan = $this->leads($by, $planId);

        if (trim($who) === '' || trim($what) === '') {
            throw ValidationException::withMessages(['what' => ['Say who does what.']]);
        }

        DB::transaction(function () use ($by, $plan, $who, $what, $when): void {
            DB::table('support_actions')->insert(['id' => Ids::next(), 'plan_id' => $plan->id, 'who' => mb_substr(trim($who), 0, 120), 'what' => mb_substr(trim($what), 0, 255),
                'when' => $when !== null && trim($when) !== '' ? mb_substr(trim($when), 0, 60) : null, 'added_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(SupportAudit::PlanChanged, subject: $plan, actor: $this->audit->actorFor($by), at: $plan->journeyScope(), meta: ['added' => 'action']);
        });
    }

    public function agree(User $by, string $planId): void
    {
        $plan = $this->leads($by, $planId);
        DB::transaction(fn () => $this->engine->perform($plan, $by, StepId::Agree, OutcomeId::Agreed));
    }

    /**
     * A review: each goal met, partly or not yet, a note, and the next review.
     *
     * @param  array<string, string>  $goals  goal id => state
     */
    public function review(User $by, string $planId, array $goals, ?string $notes, string $nextReview): void
    {
        $plan = $this->leads($by, $planId);

        if ($plan->state !== State::Reviewing->value) {
            throw ValidationException::withMessages(['review' => ['Agree the plan before reviewing it.']]);
        }

        $known = array_values(array_filter(DB::table('support_goals')->where('plan_id', $plan->id)->pluck('id')->all(), 'is_string'));

        foreach ($goals as $goal => $state) {
            if (! in_array($goal, $known, true) || ! in_array($state, self::GOAL_STATES, true)) {
                throw ValidationException::withMessages(['goals' => ['Mark each goal met, partly or not yet.']]);
            }
        }
        if ($nextReview <= $this->clock->today()) {
            throw ValidationException::withMessages(['next_review_on' => ['Give the next review a day after today.']]);
        }

        DB::transaction(function () use ($by, $plan, $goals, $notes, $nextReview): void {
            $id = Ids::next();
            DB::table('support_reviews')->insert(['id' => $id, 'plan_id' => $plan->id, 'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 4000) : null,
                'next_review_on' => $nextReview, 'reviewed_by' => $by->id, 'created_at' => $this->clock->now()]);

            foreach ($goals as $goal => $state) {
                DB::table('support_review_goals')->insert(['id' => Ids::next(), 'review_id' => $id, 'goal_id' => $goal, 'state' => $state]);
            }

            DB::table('support_plans')->where('id', $plan->id)->update(['next_review_on' => $nextReview, 'updated_at' => $this->clock->now()]);
            $this->audit->record(SupportAudit::Reviewed, subject: $plan, actor: $this->audit->actorFor($by), at: $plan->journeyScope(), meta: ['goals' => count($goals)]);
        });
    }

    public function close(User $by, string $planId, OutcomeId $outcome, string $note): void
    {
        $plan = $this->leads($by, $planId);
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => ['Say how the plan ended.']]);
        }

        DB::transaction(function () use ($by, $plan, $outcome, $note): void {
            $this->engine->perform($plan, $by, StepId::Close, $outcome, ['reason' => $note]);
            DB::table('support_plans')->where('id', $plan->id)->update(['outcome' => $outcome->value, 'closing_note' => mb_substr($note, 0, 4000), 'updated_at' => $this->clock->now()]);

            if ($plan->tell_family && ! $plan->confidential) {
                $this->notifier->send(new PlanNotice($plan->id, $plan->learner_id, $plan->class_id, 'closed', $plan->title, self::name($plan->lead_by)), $by);
            }
        });
    }

    /**
     * The part written for the family (D-110): what the school is doing and
     * what the family can do at home, and whether the family sees it. A
     * confidential plan is never shown to the family.
     */
    public function saveFamily(User $by, string $planId, string $school, string $home, bool $shown): void
    {
        $plan = $this->leads($by, $planId);
        $school = trim($school);
        $home = trim($home);

        if (mb_strlen($school) > 600 || mb_strlen($home) > 600) {
            throw ValidationException::withMessages(['family' => ['Write up to 600 characters in each part.']]);
        }
        if ($shown && $plan->confidential) {
            throw ValidationException::withMessages(['shown' => ['A confidential plan cannot be shown to the family.']]);
        }
        if ($shown && ($school === '' || $home === '')) {
            throw ValidationException::withMessages(['shown' => ['Write both parts before you show the plan to the family.']]);
        }

        DB::transaction(function () use ($by, $plan, $school, $home, $shown): void {
            $plan->update(['family_school' => $school !== '' ? $school : null, 'family_home' => $home !== '' ? $home : null, 'family_shown' => $shown]);
            $this->audit->record(SupportAudit::FamilyPartSaved, subject: $plan, actor: $this->audit->actorFor($by), at: $plan->journeyScope(), meta: ['shown' => $shown]);
        });
    }

    private function leads(User $by, string $planId): SupportPlan
    {
        $plan = $this->reach->plan($by, $planId);
        $this->access->check($by, SupportPermission::Lead, $plan->journeyScope());

        if ($plan->state === State::Closed->value) {
            throw ValidationException::withMessages(['plan' => ['The plan is closed.']]);
        }

        return $plan;
    }

    /** @param array{text: string, measure: string|null, by_when: string|null} $goal */
    private function insertGoal(User $by, SupportPlan $plan, array $goal): void
    {
        if (trim($goal['text']) === '') {
            throw ValidationException::withMessages(['goals' => ['Give each goal in words.']]);
        }

        $clean = fn (?string $v, int $n): ?string => $v !== null && trim($v) !== '' ? mb_substr(trim($v), 0, $n) : null;
        DB::table('support_goals')->insert(['id' => Ids::next(), 'plan_id' => $plan->id, 'text' => mb_substr(trim($goal['text']), 0, 255),
            'measure' => $clean($goal['measure'], 255), 'by_when' => $clean($goal['by_when'], 60), 'added_by' => $by->id, 'created_at' => $this->clock->now()]);
    }

    private static function name(string $userId): string
    {
        $name = DB::table('users')->where('id', $userId)->value('name');

        return is_string($name) ? $name : '';
    }
}
