<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\Actions\ProgressionActions;
use Acadly\Core\Enrolment\Application\Progression;
use Acadly\Core\Enrolment\Domain\ProgressionDecision;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The year-end run: promote, repeat, complete, leave, and the intake of the next level group (D-088). */
final class ProgressionController
{
    /**
     * The year-end runs.
     *
     * Each level group the viewer may run, with its run's state and the
     * learners another group hands on, waiting for its intake.
     *
     * @response array{from_year: array{id: string, name: string}|null, to_year: array{id: string, name: string, starts_on: string}|null, groups: list<array{id: string, name: string, state: 'open'|'committed'|null, intake: int}>}
     */
    public function index(Request $request, Progression $progression): JsonResponse
    {
        return response()->json($progression->index(self::user($request)));
    }

    /**
     * One level group's year-end run.
     *
     * @response array{division: array{id: string, name: string, levels: list<array{code: string, label: string}>}, from_year: array{id: string, name: string, ends_on: string}|null, to_year: array{id: string, name: string, starts_on: string}|null, run: array{id: string, state: 'open'|'committed', committed_at: string|null, committed_by: string|null}|null, decisions: list<array{id: string, learner_id: string, learner: string, class_id: string, class: string, level: string, outcome: 'promoted'|'repeated'|'progressed'|'completed'|'left', to_class_id: string|null, to_class: string|null, reason: string|null, changed: bool, state: 'proposed'|'decided'|'awaiting_intake'|'effective'|'cancelled'}>, columns: list<array{label: string, values: array<string, string>}>, targets: list<array{id: string, name: string, division_id: string, campus_id: string, level_code: string}>, checks: list<string>, totals: array{learners: int, promoted: int, repeated: int, progressed: int, completed: int, left: int, to_decide: int}, intake: list<array{id: string, learner_id: string, learner: string, class_id: string, class: string, level: string, outcome: 'promoted'|'repeated'|'progressed'|'completed'|'left', to_class_id: string|null, to_class: string|null, reason: string|null, changed: bool, state: 'proposed'|'decided'|'awaiting_intake'|'effective'|'cancelled'}>}
     */
    public function show(Request $request, string $division, Progression $progression): JsonResponse
    {
        return response()->json($progression->show(self::user($request), $division));
    }

    /** Opens the run of a level group, with an outcome proposed for every learner. The response is the same as GET. */
    public function open(Request $request, string $division, ProgressionActions $actions, Progression $progression): JsonResponse
    {
        $actions->open(self::user($request), $division);

        return $this->show($request, $division, $progression);
    }

    /** Changes a proposed outcome: `{outcome, to_class_id, reason}`. The response is the run. */
    public function change(Request $request, string $id, ProgressionActions $actions, Progression $progression): JsonResponse
    {
        $user = self::user($request);
        $outcome = $request->input('outcome');
        $class = $request->input('to_class_id');
        $reason = $request->input('reason');
        $actions->change($user, $id, is_string($outcome) ? $outcome : '', is_string($class) ? $class : null, is_string($reason) ? $reason : null);

        return $this->show($request, self::divisionOf($id), $progression);
    }

    /** Commits the run: every proposed outcome is decided, once the checks pass. The response is the run. */
    public function commit(Request $request, string $division, ProgressionActions $actions, Progression $progression): JsonResponse
    {
        $run = $progression->show(self::user($request), $division)['run'] ?? null;

        if (! is_array($run) || ! is_string($run['id'] ?? null)) {
            throw new NotFoundHttpException;
        }

        $actions->commit(self::user($request), $run['id']);

        return $this->show($request, $division, $progression);
    }

    /** The receiving level group confirms a learner, or turns them back: `{accept, to_class_id, reason}`. The response is that group's run. */
    public function intake(Request $request, string $id, ProgressionActions $actions, Progression $progression): JsonResponse
    {
        $decision = ProgressionDecision::query()->find($id) ?? throw new NotFoundHttpException;
        $class = $request->input('to_class_id');
        $reason = $request->input('reason');
        $actions->intake(self::user($request), $id, $request->boolean('accept'), is_string($class) ? $class : null, is_string($reason) ? $reason : null);

        return $this->show($request, (string) $decision->to_division_id, $progression);
    }

    private static function divisionOf(string $decisionId): string
    {
        return (ProgressionDecision::query()->find($decisionId) ?? throw new NotFoundHttpException)->from_division_id;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
