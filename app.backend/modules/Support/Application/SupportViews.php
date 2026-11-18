<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Domain\SupportPlan;
use Acadly\Modules\Support\Journeys\Plan\OutcomeId;
use Acadly\Modules\Support\Journeys\Plan\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** What the support screens read: learners to watch, a learner's support, a plan (D-093). */
final class SupportViews
{
    public function __construct(
        private readonly SupportReach $reach,
        private readonly Signals $signals,
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
        private readonly AcademicCalendar $calendar,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function watch(User $viewer): array
    {
        $visible = [];

        foreach ($this->directory->placedNow() as $id) {
            if ($this->reach->may($viewer, SupportPermission::View, $id) || $this->reach->may($viewer, SupportPermission::Lead, $id)) {
                $visible[] = $id;
            }
        }

        $signals = $this->signals->of($visible);
        $today = $this->clock->today();
        $asides = [];

        foreach ($signals === [] ? [] : DB::table('support_set_asides')->whereIn('learner_id', array_keys($signals))->where('until', '>=', $today)->orderBy('created_at')
            ->get(['learner_id', 'until', 'reason', 'signals']) as $a) {
            $asides[Row::text($a, 'learner_id')] = $a;
        }

        $plans = $this->openPlans($viewer, array_keys($signals));
        $names = $this->directory->names(array_map('strval', array_keys($signals)));
        $rows = [];
        $week = CarbonImmutable::parse($today)->addDays(7)->toDateString();

        foreach ($signals as $id => $reasons) {
            $id = (string) $id;
            $aside = $asides[$id] ?? null;
            $setAside = $aside !== null && count($reasons) <= Row::int($aside, 'signals');
            $rows[] = ['learner_id' => $id, 'name' => $names[$id] ?? '', 'class' => $this->className((string) $this->reach->classOf($id)), 'reasons' => $reasons,
                'plan' => $plans[$id] ?? null, 'set_aside' => $setAside ? ['until' => Row::text($aside, 'until'), 'reason' => Row::text($aside, 'reason')] : null,
                'can_lead' => $this->reach->may($viewer, SupportPermission::Lead, $id)];
        }

        usort($rows, fn (array $a, array $b): int => [$a['plan'] !== null, $a['set_aside'] !== null, -count($a['reasons']), $a['name']] <=> [$b['plan'] !== null, $b['set_aside'] !== null, -count($b['reasons']), $b['name']]);
        $open = array_values(array_filter($rows, fn (array $r): bool => $r['plan'] !== null));

        return [
            'learners' => $rows,
            'totals' => [
                'watch' => count($rows),
                'with_plan' => count($open),
                'without_plan' => count(array_filter($rows, fn (array $r): bool => $r['plan'] === null && $r['set_aside'] === null)),
                'reviews_due' => count(array_filter($open, fn (array $r): bool => $r['plan']['next_review_on'] <= $week)),
            ],
            'sources' => array_map(fn ($s): array => ['key' => $s->key(), 'label' => $s->label()], $this->signals->sources()),
        ];
    }

    /** @return array<string, mixed> */
    public function learner(User $viewer, string $learnerId): array
    {
        $this->reach->learner($viewer, $learnerId, SupportPermission::View);
        $plans = [];

        foreach (SupportPlan::query()->where('learner_id', $learnerId)->orderByDesc('created_at')->get() as $plan) {
            if ($this->reach->seesPlan($viewer, $plan)) {
                $plans[] = $this->summary($plan);
            }
        }

        return [
            'trends' => $this->signals->trends($learnerId),
            'signals' => $this->signals->of([$learnerId])[$learnerId] ?? [],
            'plans' => $plans,
            'can' => ['lead' => $this->reach->may($viewer, SupportPermission::Lead, $learnerId), 'confidential' => $this->reach->may($viewer, SupportPermission::Confidential, $learnerId)],
        ];
    }

    /**
     * What opening a plan for a learner needs: the signals now, who may lead, the defaults.
     *
     * @return array<string, mixed>
     */
    public function opening(User $viewer, string $learnerId): array
    {
        $at = $this->reach->learner($viewer, $learnerId, SupportPermission::Lead);
        $leads = [];

        foreach ($this->access->holderIds(SupportPermission::Lead, $at) as $id) {
            $name = DB::table('users')->where('id', $id)->value('name');

            if (is_string($name)) {
                $leads[] = ['id' => $id, 'name' => $name];
            }
        }

        usort($leads, fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $teacher = $this->calendar->classTeacherId((string) $at->id);
        $values = Signals::values();

        return [
            'learner' => ['id' => $learnerId, 'name' => $this->directory->names([$learnerId])[$learnerId] ?? '', 'class' => $this->className((string) $at->id)],
            'signals' => $this->signals->of([$learnerId])[$learnerId] ?? [],
            'leads' => $leads,
            'lead' => $teacher !== null && in_array($teacher, array_column($leads, 'id'), true) ? $teacher : $viewer->id,
            'first_review' => CarbonImmutable::parse($this->clock->today())->addDays(max(1, $values['plan.review_days'] ?? 14))->toDateString(),
            'tell_family' => ($values['plan.tell_family'] ?? 1) === 1,
            'can_confidential' => $this->access->allows($viewer, SupportPermission::Confidential, $at),
        ];
    }

    /** @return array<string, mixed> */
    public function plan(User $viewer, string $planId): array
    {
        $plan = $this->reach->plan($viewer, $planId);
        $goals = [];
        $latest = [];

        foreach (DB::table('support_review_goals as g')->join('support_reviews as r', 'r.id', '=', 'g.review_id')->where('r.plan_id', $plan->id)->orderBy('r.created_at')
            ->get(['g.goal_id', 'g.state']) as $g) {
            $latest[Row::text($g, 'goal_id')] = Row::text($g, 'state');
        }

        foreach (DB::table('support_goals')->where('plan_id', $plan->id)->orderBy('created_at')->get(['id', 'text', 'measure', 'by_when']) as $g) {
            $goals[] = ['id' => Row::text($g, 'id'), 'text' => Row::text($g, 'text'), 'measure' => Row::maybeText($g, 'measure'), 'by_when' => Row::maybeText($g, 'by_when'),
                'state' => $latest[Row::text($g, 'id')] ?? null];
        }

        $actions = [];

        foreach (DB::table('support_actions')->where('plan_id', $plan->id)->orderBy('created_at')->get(['id', 'who', 'what', 'when']) as $a) {
            $actions[] = ['id' => Row::text($a, 'id'), 'who' => Row::text($a, 'who'), 'what' => Row::text($a, 'what'), 'when' => Row::maybeText($a, 'when')];
        }

        $reviews = [];

        foreach (DB::table('support_reviews as r')->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')->where('r.plan_id', $plan->id)->orderBy('r.created_at')
            ->get(['r.id', 'r.notes', 'r.created_at', 'u.name']) as $r) {
            $states = DB::table('support_review_goals')->where('review_id', $r->id)->pluck('state', 'goal_id')->all();
            $reviews[] = ['id' => Row::text($r, 'id'), 'notes' => Row::maybeText($r, 'notes'), 'at' => Row::text($r, 'created_at'), 'by' => Row::maybeText($r, 'name'),
                'met' => count(array_filter($states, fn ($s): bool => $s === 'met')), 'of' => count($states)];
        }

        $lead = $this->reach->may($viewer, SupportPermission::Lead, $plan->learner_id) && $plan->state !== State::Closed->value;

        return [
            ...$this->summary($plan),
            'concern' => $plan->concern,
            'signals' => $plan->signals === null ? [] : explode("\n", $plan->signals),
            'learner' => ['id' => $plan->learner_id, 'name' => $this->directory->names([$plan->learner_id])[$plan->learner_id] ?? '', 'class' => $this->className($plan->class_id)],
            'opened_by' => self::name($plan->opened_by),
            'goals' => $goals,
            'actions' => $actions,
            'reviews' => $reviews,
            'closing_note' => $plan->closing_note,
            'can' => ['lead' => $lead, 'agree' => $lead && $plan->state === State::Raised->value, 'review' => $lead && $plan->state === State::Reviewing->value, 'close' => $lead],
        ];
    }

    /** @return array{id: string, title: string, state: string, state_label: string, lead: string, confidential: bool, tell_family: bool, next_review_on: string, opened_on: string, outcome: string|null, outcome_label: string|null} */
    private function summary(SupportPlan $plan): array
    {
        $outcome = $plan->outcome === null ? null : OutcomeId::tryFrom($plan->outcome);

        return ['id' => $plan->id, 'title' => $plan->title, 'state' => $plan->state, 'state_label' => State::from($plan->state)->label(), 'lead' => self::name($plan->lead_by),
            'confidential' => $plan->confidential, 'tell_family' => $plan->tell_family, 'next_review_on' => $plan->next_review_on->toDateString(),
            'opened_on' => $plan->created_at->toDateString(), 'outcome' => $plan->outcome, 'outcome_label' => $outcome?->label()];
    }

    /**
     * @param  list<int|string>  $learnerIds
     * @return array<string, array{id: string, state_label: string, lead: string, next_review_on: string}>
     */
    private function openPlans(User $viewer, array $learnerIds): array
    {
        $out = [];

        foreach ($learnerIds === [] ? [] : SupportPlan::query()->whereIn('learner_id', array_map('strval', $learnerIds))->where('state', '!=', State::Closed->value)->get() as $plan) {
            if ($this->reach->seesPlan($viewer, $plan)) {
                $out[$plan->learner_id] = ['id' => $plan->id, 'state_label' => State::from($plan->state)->label(), 'lead' => self::name($plan->lead_by), 'next_review_on' => $plan->next_review_on->toDateString()];
            }
        }

        return $out;
    }

    private function className(string $classId): string
    {
        return $classId === '' ? '' : ($this->calendar->classNames([$classId])[$classId]['name'] ?? '');
    }

    private static function name(string $userId): string
    {
        $name = DB::table('users')->where('id', $userId)->value('name');

        return is_string($name) ? $name : '';
    }

    /** Kept for the scope of a whole-school check. */
    public static function school(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Institution, null);
    }
}
