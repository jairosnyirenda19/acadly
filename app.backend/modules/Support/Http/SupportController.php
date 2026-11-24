<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Support\Application\Actions\PlanActions;
use Acadly\Modules\Support\Application\Actions\RuleActions;
use Acadly\Modules\Support\Application\Signals;
use Acadly\Modules\Support\Application\SupportReach;
use Acadly\Modules\Support\Application\SupportViews;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Journeys\Plan\OutcomeId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Support over the API (D-093). */
final class SupportController
{
    /**
     * Learners to watch, across the modules that are on.
     *
     * @response array{learners: list<array{learner_id: string, name: string, class: string, reasons: list<array{source: string, label: string, text: string}>, plan: array{id: string, state_label: string, lead: string, next_review_on: string}|null, set_aside: array{until: string, reason: string}|null, can_lead: bool}>, totals: array{watch: int, with_plan: int, without_plan: int, reviews_due: int}, sources: list<array{key: string, label: string}>}
     */
    public function watch(Request $request, SupportViews $views, Access $access): JsonResponse
    {
        $who = self::user($request);

        if (! self::holdsAnywhere($access, $who, SupportPermission::View) && ! self::holdsAnywhere($access, $who, SupportPermission::Lead)) {
            $access->check($who, SupportPermission::View);
        }

        return response()->json($views->watch($who));
    }

    /**
     * A learner's support: trends by term, signals now, plans.
     *
     * @response array{trends: array{terms: list<array{key: string, name: string, current: bool}>, rows: list<array{source: string, key: string, label: string, unit: string, values: array<string, float|null>, worse: string, change: string|null}>}, signals: list<array{source: string, label: string, text: string}>, plans: list<array{id: string, title: string, state: string, state_label: string, lead: string, confidential: bool, tell_family: bool, next_review_on: string, opened_on: string, outcome: string|null, outcome_label: string|null}>, can: array{lead: bool, confidential: bool}}
     */
    public function learner(Request $request, string $id, SupportViews $views): JsonResponse
    {
        return response()->json($views->learner(self::user($request), $id));
    }

    /**
     * What opening a plan for a learner needs.
     *
     * @response array{learner: array{id: string, name: string, class: string}, signals: list<array{source: string, label: string, text: string}>, leads: list<array{id: string, name: string}>, lead: string, first_review: string, tell_family: bool, can_confidential: bool}
     */
    public function opening(Request $request, string $id, SupportViews $views): JsonResponse
    {
        return response()->json($views->opening(self::user($request), $id));
    }

    /**
     * Opens a support plan.
     *
     * @response 201 array{id: string}
     */
    public function open(Request $request, PlanActions $plans, SupportReach $reach): JsonResponse
    {
        // Refused before anything about the form is said.
        $reach->learner(self::user($request), $request->string('learner_id')->toString(), SupportPermission::Lead);
        $request->validate([
            'title' => ['required', 'string', 'max:120'], 'concern' => ['required', 'string', 'max:4000'], 'lead_id' => ['nullable', 'uuid'],
            'confidential' => ['sometimes', 'boolean'], 'tell_family' => ['sometimes', 'boolean'], 'first_review' => ['nullable', 'date_format:Y-m-d'],
            'goals' => ['sometimes', 'array', 'max:12'], 'goals.*.text' => ['required', 'string', 'max:255'], 'goals.*.measure' => ['nullable', 'string', 'max:255'], 'goals.*.by_when' => ['nullable', 'string', 'max:60'],
        ]);
        $first = $request->string('first_review')->toString();
        $lead = $request->string('lead_id')->toString();
        $plan = $plans->open(self::user($request), $request->string('learner_id')->toString(), $request->string('title')->toString(), $request->string('concern')->toString(),
            $lead !== '' ? $lead : null, $request->boolean('confidential'), $request->has('tell_family') ? $request->boolean('tell_family') : null, $first !== '' ? $first : null, self::goals($request));

        return response()->json(['id' => $plan->id], 201);
    }

    /**
     * A support plan.
     *
     * @response array{id: string, title: string, state: string, state_label: string, lead: string, confidential: bool, tell_family: bool, next_review_on: string, opened_on: string, outcome: string|null, outcome_label: string|null, concern: string, signals: list<string>, learner: array{id: string, name: string, class: string}, opened_by: string, goals: list<array{id: string, text: string, measure: string|null, by_when: string|null, state: string|null}>, actions: list<array{id: string, who: string, what: string, when: string|null}>, reviews: list<array{id: string, notes: string|null, at: string, by: string|null, met: int, of: int}>, closing_note: string|null, family: array{school: string|null, home: string|null, shown: bool}, can: array{family: bool, lead: bool, agree: bool, review: bool, close: bool}}
     */
    public function plan(Request $request, string $id, SupportViews $views): JsonResponse
    {
        return response()->json($views->plan(self::user($request), $id));
    }

    /**
     * Adds a goal or an action, agrees, reviews or closes a plan.
     *
     * @response array{id: string}
     */
    public function change(Request $request, string $id, string $what, PlanActions $plans, SupportReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->plan($who, $id);

        match ($what) {
            'goals' => $plans->addGoal($who, $id, ['text' => $request->string('text')->toString(), 'measure' => $request->string('measure')->toString(), 'by_when' => $request->string('by_when')->toString()]),
            'actions' => $plans->addAction($who, $id, $request->string('who')->toString(), $request->string('what')->toString(), $request->string('when')->toString()),
            'agree' => $plans->agree($who, $id),
            'reviews' => $plans->review($who, $id, self::states($request), $request->string('notes')->toString(), $request->string('next_review_on')->toString()),
            default => $plans->close($who, $id, $this->outcome($request), $request->string('note')->toString()),
        };

        return response()->json(['id' => $id]);
    }

    /**
     * Saves the part of a plan written for the family, and whether the family sees it.
     *
     * @response array{id: string}
     */
    public function family(Request $request, string $id, PlanActions $plans, SupportReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->plan($who, $id);
        $request->validate(['school' => ['nullable', 'string', 'max:600'], 'home' => ['nullable', 'string', 'max:600'], 'shown' => ['required', 'boolean']]);
        $plans->saveFamily($who, $id, $request->string('school')->toString(), $request->string('home')->toString(), $request->boolean('shown'));

        return response()->json(['id' => $id]);
    }

    /**
     * Sets a learner's signals aside until a day, with a reason.
     *
     * @response array{set_aside: bool}
     */
    public function setAside(Request $request, string $id, RuleActions $rules): JsonResponse
    {
        $rules->setAside(self::user($request), $id, $request->string('until')->toString(), $request->string('reason')->toString());

        return response()->json(['set_aside' => true]);
    }

    /**
     * The signal rules with the school's values, and the plan defaults.
     *
     * @response array{rules: list<array{source: string, label: string, key: string, text: string, value: int, default: int}>, review_days: int, tell_family: bool}
     */
    public function rules(Request $request, Access $access, Signals $signals): JsonResponse
    {
        $access->check(self::user($request), SupportPermission::Manage);
        $values = Signals::values();

        return response()->json(['rules' => $signals->rules(), 'review_days' => $values['plan.review_days'] ?? 14, 'tell_family' => ($values['plan.tell_family'] ?? 1) === 1]);
    }

    /**
     * Saves the rules.
     *
     * @response array{saved: bool}
     */
    public function saveRules(Request $request, Access $access, RuleActions $rules): JsonResponse
    {
        $access->check(self::user($request), SupportPermission::Manage);
        $request->validate(['values' => ['required', 'array']]);
        $values = [];

        foreach ((array) $request->input('values') as $key => $value) {
            if (is_string($key) && (is_int($value) || (is_string($value) && is_numeric($value)))) {
                $values[$key] = (int) $value;
            }
        }

        $rules->save(self::user($request), $values);

        return response()->json(['saved' => true]);
    }

    private function outcome(Request $request): OutcomeId
    {
        $request->validate(['outcome' => ['required', Rule::in(['goals_met', 'partly_met', 'referred', 'left'])], 'note' => ['required', 'string', 'max:4000']]);

        return OutcomeId::from($request->string('outcome')->toString());
    }

    /** @return list<array{text: string, measure: string|null, by_when: string|null}> */
    private static function goals(Request $request): array
    {
        $out = [];

        foreach ((array) $request->input('goals') as $g) {
            if (is_array($g)) {
                $out[] = ['text' => is_string($g['text'] ?? null) ? $g['text'] : '', 'measure' => is_string($g['measure'] ?? null) ? $g['measure'] : null, 'by_when' => is_string($g['by_when'] ?? null) ? $g['by_when'] : null];
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function states(Request $request): array
    {
        $out = [];

        foreach ((array) $request->input('goals') as $goal => $state) {
            if (is_string($goal) && is_string($state)) {
                $out[$goal] = $state;
            }
        }

        return $out;
    }

    private static function holdsAnywhere(Access $access, User $who, SupportPermission $permission): bool
    {
        foreach ($access->grants($who) as $grant) {
            if ($grant->permission === $permission->value) {
                return true;
            }
        }

        return false;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
