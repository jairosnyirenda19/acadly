<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals\Http;

use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Approvals\Actions\SaveChain;
use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Approvals (the approved design): what waits for you, one request, and the catalogue of chains. */
final class ApprovalsController
{
    public function __construct(private readonly ApprovalRequests $requests) {}

    /**
     * Everything waiting for your answer, oldest first.
     *
     * @response array{waiting: list<array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, asked_by: string, step: int, steps: int, step_label: string, asked_at: string|null}>}
     */
    public function waiting(Request $request): JsonResponse
    {
        return response()->json(['waiting' => $this->requests->waitingFor(self::user($request))]);
    }

    /**
     * One request and how its chain stands.
     *
     * @response array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, link: string, state: 'waiting'|'approved'|'declined'|'withdrawn', asked_by: string, asked_at: string, decided_at: string|null, steps: list<array{position: int, label: string, state: 'pending'|'waiting'|'approved'|'declined'|'skipped'|'withdrawn', needs: int, asked: list<string>, note: string|null, answers: list<array{by: string, answer: 'approved'|'declined', reason: string|null, at: string}>}>, can_answer: bool, can_withdraw: bool}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->requests->show(self::user($request), $id));
    }

    /**
     * Answers the step waiting for you: `{approve, reason}`. A decline needs a reason. The response is the request as it now stands.
     *
     * @response array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, link: string, state: 'waiting'|'approved'|'declined'|'withdrawn', asked_by: string, asked_at: string, decided_at: string|null, steps: list<array{position: int, label: string, state: 'pending'|'waiting'|'approved'|'declined'|'skipped'|'withdrawn', needs: int, asked: list<string>, note: string|null, answers: list<array{by: string, answer: 'approved'|'declined', reason: string|null, at: string}>}>, can_answer: bool, can_withdraw: bool}
     */
    public function answer(Request $request, string $id, ApprovalActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $reason = $request->input('reason');
        $actions->answer($viewer, $id, $request->boolean('approve'), is_string($reason) ? $reason : null);

        return response()->json($this->requests->show($viewer, $id));
    }

    /**
     * Takes back a request you asked for, while it waits. Nothing changes. The response is the request as it now stands.
     *
     * @response array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, link: string, state: 'waiting'|'approved'|'declined'|'withdrawn', asked_by: string, asked_at: string, decided_at: string|null, steps: list<array{position: int, label: string, state: 'pending'|'waiting'|'approved'|'declined'|'skipped'|'withdrawn', needs: int, asked: list<string>, note: string|null, answers: list<array{by: string, answer: 'approved'|'declined', reason: string|null, at: string}>}>, can_answer: bool, can_withdraw: bool}
     */
    public function withdraw(Request $request, string $id, ApprovalActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $row = DB::table('approval_requests')->where('id', $id)->first(['topic_key', 'subject_id']);

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        if (! $actions->withdraw($viewer, Row::text($row, 'topic_key'), Row::text($row, 'subject_id'), 'Withdrawn by the person who asked.')) {
            throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
        }

        return response()->json($this->requests->show($viewer, $id));
    }

    /**
     * The catalogue: every kind of request that needs an approval, with its chain.
     *
     * @response array{areas: list<array{area: string, topics: list<array{key: string, title: string, description: string, steps: list<string>, changed: bool, optional: bool}>}>, can_edit: bool}
     */
    public function topics(Request $request): JsonResponse
    {
        return response()->json($this->requests->catalogue(self::user($request)));
    }

    /**
     * One kind of request: its chain and every choice for its steps.
     *
     * @response array{key: string, title: string, area: string, description: string, permission: string, optional: bool, chain: array{steps: list<array{kind: 'anyone'|'relation'|'role'|'person', target: string|null, needs: int, when: string|null}>, fallback_role_id: string|null, changed: bool, saved_by: string|null, saved_at: string|null}, labels: list<string>, relations: list<array{key: string, label: string}>, facts: list<array{key: string, label: string}>, roles: list<array{id: string, name: string}>, people: list<array{id: string, name: string}>, can_edit: bool}
     */
    public function topic(Request $request, string $key): JsonResponse
    {
        return response()->json($this->requests->topic(self::user($request), $key));
    }

    /**
     * Saves the chain of a kind of request: `{steps: [{kind, target, needs, when}], fallback_role_id}`. No steps puts the default back.
     *
     * @response array{key: string, title: string, area: string, description: string, permission: string, optional: bool, chain: array{steps: list<array{kind: 'anyone'|'relation'|'role'|'person', target: string|null, needs: int, when: string|null}>, fallback_role_id: string|null, changed: bool, saved_by: string|null, saved_at: string|null}, labels: list<string>, relations: list<array{key: string, label: string}>, facts: list<array{key: string, label: string}>, roles: list<array{id: string, name: string}>, people: list<array{id: string, name: string}>, can_edit: bool}
     */
    public function save(Request $request, string $key, SaveChain $save): JsonResponse
    {
        $viewer = self::user($request);
        $steps = $request->input('steps');
        $fallback = $request->input('fallback_role_id');
        $save->handle($viewer, $key, is_array($steps) ? $steps : [], is_string($fallback) && $fallback !== '' ? $fallback : null);

        return response()->json($this->requests->topic($viewer, $key));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
