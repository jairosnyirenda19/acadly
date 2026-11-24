<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Conduct\Application\Actions\CaseActions;
use Acadly\Modules\Conduct\Application\Actions\EntryActions;
use Acadly\Modules\Conduct\Application\Actions\KindActions;
use Acadly\Modules\Conduct\Application\ConductReach;
use Acadly\Modules\Conduct\Application\ConductViews;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\Level;
use Acadly\Modules\Conduct\Domain\Sanction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Conduct over the API (D-092). */
final class ConductController
{
    /**
     * The kinds of incident and commendation, and the rules for learners to watch.
     *
     * @response array{kinds: list<array{id: string, name: string, commendation: bool, level: string|null, always: bool, retired: bool}>, watch: array{incidents: int, days: int, open_serious: bool, missed_incidents: int, missed_lessons: int, missed_days: int, portal: bool}}
     */
    public function kinds(Request $request, Access $access): JsonResponse
    {
        $who = self::user($request);
        $kinds = [];

        foreach (DB::table('conduct_kinds')->orderBy('position')->get(['id', 'name', 'commendation', 'level', 'tell', 'retired_at']) as $k) {
            $kinds[] = ['id' => Row::text($k, 'id'), 'name' => Row::text($k, 'name'), 'commendation' => (bool) $k->commendation, 'level' => Row::maybeText($k, 'level'),
                'always' => Row::text($k, 'tell') === 'always', 'retired' => $k->retired_at !== null];
        }

        // The list says nothing about a place; it is shown to anyone who records or sets conduct.
        $shown = $access->allows($who, ConductPermission::Manage) || self::holdsAnywhere($access, $who, ConductPermission::Record);

        return response()->json(['kinds' => $shown ? $kinds : [], 'watch' => ConductViews::rules()]);
    }

    /**
     * Saves the kinds.
     *
     * @response array{saved: bool}
     */
    public function saveKinds(Request $request, Access $access, KindActions $actions): JsonResponse
    {
        $access->check(self::user($request), ConductPermission::Manage);
        $request->validate(['kinds' => ['required', 'array', 'max:80'], 'kinds.*.id' => ['nullable', 'uuid'], 'kinds.*.name' => ['required', 'string', 'max:80'],
            'kinds.*.commendation' => ['required', 'boolean'], 'kinds.*.level' => ['nullable', Rule::enum(Level::class)], 'kinds.*.always' => ['required', 'boolean'],
            'kinds.*.retired' => ['required', 'boolean']]);
        $kinds = [];

        foreach (self::rows($request, 'kinds') as $k) {
            $level = is_string($k['level'] ?? null) ? Level::tryFrom($k['level']) : null;
            $kinds[] = ['id' => is_string($k['id'] ?? null) && $k['id'] !== '' ? $k['id'] : null, 'name' => is_string($k['name'] ?? null) ? $k['name'] : '',
                'commendation' => ($k['commendation'] ?? false) === true, 'level' => $level, 'always' => ($k['always'] ?? false) === true, 'retired' => ($k['retired'] ?? false) === true];
        }

        $actions->saveKinds(self::user($request), $kinds);

        return response()->json(['saved' => true]);
    }

    /**
     * Saves the rules for learners to watch.
     *
     * @response array{saved: bool}
     */
    public function saveWatch(Request $request, Access $access, KindActions $actions): JsonResponse
    {
        $access->check(self::user($request), ConductPermission::Manage);
        $request->validate(['incidents' => ['required', 'integer'], 'days' => ['required', 'integer'], 'open_serious' => ['required', 'boolean'],
            'missed_incidents' => ['required', 'integer'], 'missed_lessons' => ['required', 'integer'], 'missed_days' => ['required', 'integer'], 'portal' => ['required', 'boolean']]);
        $actions->saveWatch(self::user($request), ['incidents' => $request->integer('incidents'), 'days' => $request->integer('days'), 'open_serious' => $request->boolean('open_serious'),
            'missed_incidents' => $request->integer('missed_incidents'), 'missed_lessons' => $request->integer('missed_lessons'), 'missed_days' => $request->integer('missed_days'),
            'portal' => $request->boolean('portal')]);

        return response()->json(['saved' => true]);
    }

    /**
     * Records an incident or a commendation for one or more learners.
     *
     * @response 201 array{entry_id: string, case_id: string|null}
     */
    public function record(Request $request, EntryActions $entries, ConductReach $reach): JsonResponse
    {
        $learners = [];

        foreach ((array) $request->input('learners') as $l) {
            if (is_string($l)) {
                $learners[] = $l;
            }
        }

        // Refused before anything about the form is said.
        $reach->learner(self::user($request), $learners[0] ?? '', ConductPermission::Record);
        $request->validate([
            'kind_id' => ['required', 'uuid'],
            'level' => ['nullable', Rule::enum(Level::class)],
            'learners' => ['required', 'array', 'min:1', 'max:'.EntryActions::MAX_LEARNERS],
            'learners.*' => ['uuid'],
            'happened_on' => ['required', 'date_format:Y-m-d'],
            'place' => ['nullable', 'string', 'max:120'],
            'what' => ['required', 'string', 'max:4000'],
            'action' => ['nullable', 'string', 'max:2000'],
            'tell' => ['sometimes', 'boolean'],
        ]);
        $level = $request->string('level')->toString();

        return response()->json($entries->record(self::user($request), $request->string('kind_id')->toString(), $level !== '' ? Level::from($level) : null, $learners,
            $request->string('happened_on')->toString(), $request->string('place')->toString(), $request->string('what')->toString(), $request->string('action')->toString(),
            $request->boolean('tell')), 201);
    }

    /**
     * Withdraws an entry, with a reason.
     *
     * @response array{withdrawn: bool}
     */
    public function withdraw(Request $request, string $id, EntryActions $entries): JsonResponse
    {
        $entries->withdraw(self::user($request), $id, $request->string('reason')->toString());

        return response()->json(['withdrawn' => true]);
    }

    /**
     * A learner's conduct: the entries, by term, why to watch.
     *
     * @response array{entries: list<array{id: string, kind: string, commendation: bool, level: string|null, happened_on: string, place: string|null, what: string, action: string|null, told_family: bool, recorded_by: string|null, recorded_by_id: string, withdrawn: array{reason: string, by: string|null}|null, case: array{id: string, number: string, state: string, state_label: string}|null, others: list<array{id: string, name: string}>}>, terms: list<array{name: string, incidents: int, commendations: int, current: bool}>, watch: list<string>, can: array{record: bool, follow_up: bool}}
     */
    public function learner(Request $request, string $id, ConductViews $views): JsonResponse
    {
        return response()->json($views->learner(self::user($request), $id));
    }

    /**
     * The school's overview: open serious incidents, learners to watch, by kind.
     *
     * @response array{commendations: int, commendations_before: int, incidents: int, levels: array<string, int>, open_cases: list<array{id: string, number: string, state: string, state_label: string, kind: string, happened_on: string, learners: string, lead: string|null, days: int}>, watch: list<array{id: string, name: string, class: string, reasons: list<string>}>, kinds: list<array{name: string, commendation: bool, now: int, before: int}>, rules: array{incidents: int, days: int, open_serious: bool, missed_incidents: int, missed_lessons: int, missed_days: int, portal: bool}}
     */
    public function overview(Request $request, ConductViews $views): JsonResponse
    {
        return response()->json($views->overview(self::user($request)));
    }

    /**
     * A serious incident.
     *
     * @response array{id: string, number: string, state: string, state_label: string, entry: array{id: string, kind: string, commendation: bool, level: string|null, happened_on: string, place: string|null, what: string, action: string|null, told_family: bool, recorded_by: string|null, recorded_by_id: string, withdrawn: array{reason: string, by: string|null}|null, case: array{id: string, number: string, state: string, state_label: string}|null, others: list<array{id: string, name: string}>}|null, lead: string|null, learners: list<array{id: string, name: string, class: string, outcome: array{outcome: string, text: string, reason: string, ended_after: string|null, can_end: bool}|null}>, notes: list<array{id: string, text: string, at: string, by: string|null}>, documents: list<array{id: string, title: string}>, can: array{follow_up: bool, take_up: bool, decide: bool, meet: bool, close: bool, withdraw: bool, reporter: bool}}
     */
    public function incident(Request $request, string $id, ConductViews $views): JsonResponse
    {
        return response()->json($views->incident(self::user($request), $id));
    }

    /**
     * Moves a serious incident on: take it up, decide, the family met or told, close, withdraw.
     *
     * @response array{id: string}
     */
    public function step(Request $request, string $id, string $step, CaseActions $cases): JsonResponse
    {
        $who = self::user($request);
        $cases->find($who, $id);
        $reason = $request->string('reason')->toString();

        match ($step) {
            'take-up' => $cases->takeUp($who, $id),
            'decide' => $cases->decide($who, $id),
            'met' => $cases->familyMet($who, $id, true, null),
            'told' => $cases->familyMet($who, $id, false, $reason),
            'close' => $cases->close($who, $id),
            default => $cases->withdraw($who, $id, $reason),
        };

        return response()->json(['id' => $id]);
    }

    /**
     * Adds a note to a serious incident.
     *
     * @response 201 array{id: string}
     */
    public function note(Request $request, string $id, CaseActions $cases): JsonResponse
    {
        $cases->find(self::user($request), $id);
        $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $cases->addNote(self::user($request), $id, $request->string('text')->toString());

        return response()->json(['id' => $id], 201);
    }

    /**
     * Gives one learner's outcome of a serious incident.
     *
     * @response 201 array{id: string}
     */
    public function outcome(Request $request, string $id, CaseActions $cases): JsonResponse
    {
        $cases->find(self::user($request), $id);
        $request->validate(['learner_id' => ['required', 'uuid'], 'outcome' => ['required', Rule::enum(Sanction::class)], 'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $starts = $request->string('starts_on')->toString();
        $cases->giveOutcome(self::user($request), $id, $request->string('learner_id')->toString(), Sanction::from($request->string('outcome')->toString()),
            $starts !== '' ? $starts : null, $request->filled('days') ? $request->integer('days') : null, $request->string('reason')->toString());

        return response()->json(['id' => $id], 201);
    }

    /**
     * Ends a learner's suspension early, with a reason. Today's register opens again.
     *
     * @response array{id: string}
     */
    public function endSuspension(Request $request, string $id, string $learner, CaseActions $cases): JsonResponse
    {
        $cases->find(self::user($request), $id);
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $cases->endSuspension(self::user($request), $id, $learner, $request->string('reason')->toString());

        return response()->json(['id' => $id]);
    }

    /** @return list<array<mixed>> */
    private static function rows(Request $request, string $key): array
    {
        $out = [];

        foreach ((array) $request->input($key) as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function holdsAnywhere(Access $access, User $who, ConductPermission $permission): bool
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
