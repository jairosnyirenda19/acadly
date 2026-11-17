<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the Approvals screens show (the approved design): everything waiting
 * for one person, one request and how its chain stands, and the catalogue
 * with the chain in force for each kind of request.
 *
 * @phpstan-import-type Spec from Approvals
 */
final class ApprovalRequests
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly Access $access,
    ) {}

    /**
     * Everything waiting for this person's answer, oldest first.
     *
     * @return list<array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, asked_by: string, step: int, steps: int, step_label: string, asked_at: string|null}>
     */
    public function waitingFor(User $viewer): array
    {
        $rows = DB::table('approval_steps as s')->join('approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('r.state', 'waiting')->where('s.state', 'waiting')
            ->whereRaw('s.asked @> ?::jsonb', [json_encode([$viewer->id], JSON_THROW_ON_ERROR)])
            ->whereNotExists(fn (Builder $q): Builder => $q->from('approval_answers as a')->whereColumn('a.step_id', 's.id')->where('a.user_id', $viewer->id))
            ->orderBy('s.asked_at')->get(['r.id', 'r.topic_key', 'r.subject_id', 'r.requester_id', 's.position', 's.label', 's.asked_at']);
        $out = [];

        foreach ($rows as $row) {
            $topic = $this->approvals->topic(Row::text($row, 'topic_key'));
            $case = $topic?->case(Row::text($row, 'subject_id'));

            if ($topic === null || $case === null) {
                continue;
            }

            $out[] = [
                'id' => Row::text($row, 'id'), 'kind' => $topic->title(), 'area' => $topic->area(), 'title' => $case->title, 'where' => $case->where, 'reason' => $case->reason,
                'asked_by' => $this->name(Row::text($row, 'requester_id')), 'step' => Row::int($row, 'position'),
                'steps' => DB::table('approval_steps')->where('request_id', Row::text($row, 'id'))->count(), 'step_label' => Row::text($row, 'label'),
                'asked_at' => Row::maybeText($row, 'asked_at'),
            ];
        }

        return $out;
    }

    /**
     * One request and how its chain stands. Seen by the person who asked, the people asked, and anyone who may decide it there.
     *
     * @return array{id: string, kind: string, area: string, title: string, where: string, reason: string|null, link: string, state: string, asked_by: string, asked_at: string, decided_at: string|null, steps: list<array{position: int, label: string, state: string, needs: int, asked: list<string>, note: string|null, answers: list<array{by: string, answer: string, reason: string|null, at: string}>}>, can_answer: bool, can_withdraw: bool}
     */
    public function show(User $viewer, string $id): array
    {
        $request = DB::table('approval_requests')->where('id', $id)->first();
        if ($request === null) {
            throw new NotFoundHttpException;
        }

        $topic = $this->approvals->topic(Row::text($request, 'topic_key'));
        $case = $topic?->case(Row::text($request, 'subject_id'));

        if ($topic === null || $case === null) {
            throw new NotFoundHttpException;
        }

        $steps = DB::table('approval_steps')->where('request_id', $id)->orderBy('position')->get();
        $everyone = [];

        foreach ($steps as $s) {
            $everyone = [...$everyone, ...self::ids(Row::text($s, 'asked'))];
        }

        $sees = $viewer->id === Row::text($request, 'requester_id') || in_array($viewer->id, $everyone, true) || $this->access->allows($viewer, $topic->permission(), $case->at);

        if (! $sees) {
            throw new NotFoundHttpException;
        }

        $canAnswer = false;
        $out = [];

        foreach ($steps as $s) {
            $answers = DB::table('approval_answers')->where('step_id', Row::text($s, 'id'))->orderBy('created_at')->get();
            $asked = self::ids(Row::text($s, 'asked'));

            if (Row::text($s, 'state') === 'waiting' && Row::text($request, 'state') === 'waiting' && in_array($viewer->id, $asked, true)
                && ! $answers->contains(fn (object $a): bool => Row::text($a, 'user_id') === $viewer->id)) {
                $canAnswer = true;
            }

            $out[] = [
                'position' => Row::int($s, 'position'), 'label' => Row::text($s, 'label'), 'state' => Row::text($s, 'state'), 'needs' => Row::int($s, 'needs'),
                'asked' => array_map(fn (string $u): string => $this->name($u), $asked), 'note' => Row::maybeText($s, 'note'),
                'answers' => array_values($answers->map(fn (object $a): array => ['by' => $this->name(Row::text($a, 'user_id')), 'answer' => Row::text($a, 'answer'),
                    'reason' => Row::maybeText($a, 'reason'), 'at' => Row::text($a, 'created_at')])->all()),
            ];
        }

        return [
            'id' => $id, 'kind' => $topic->title(), 'area' => $topic->area(), 'title' => $case->title, 'where' => $case->where, 'reason' => $case->reason, 'link' => $case->link,
            'state' => Row::text($request, 'state'), 'asked_by' => $this->name(Row::text($request, 'requester_id')), 'asked_at' => Row::text($request, 'created_at'),
            'decided_at' => Row::maybeText($request, 'decided_at'), 'steps' => $out, 'can_answer' => $canAnswer,
            'can_withdraw' => Row::text($request, 'state') === 'waiting' && $viewer->id === Row::text($request, 'requester_id'),
        ];
    }

    /**
     * Whether the waiting step of a record's request asks this person, and the step's words.
     * Null when no request is waiting, so the module's screens fall back to their own rule.
     *
     * @return array{asks: bool, step: string}|null
     */
    public function asks(string $topicKey, string $subjectId, User $viewer): ?array
    {
        $row = DB::table('approval_steps as s')->join('approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('r.topic_key', $topicKey)->where('r.subject_id', $subjectId)->where('r.state', 'waiting')->where('s.state', 'waiting')
            ->first(['s.id', 's.asked', 's.label']);

        if ($row === null) {
            return null;
        }

        $answered = DB::table('approval_answers')->where('step_id', Row::text($row, 'id'))->where('user_id', $viewer->id)->exists();

        return ['asks' => ! $answered && in_array($viewer->id, self::ids(Row::text($row, 'asked')), true), 'step' => Row::text($row, 'label')];
    }

    /**
     * The request of a record still waiting, for the module's own screens:
     * who asked, the step it is at and the people that step asks.
     *
     * @return array{id: string, asked_by: string, asked_by_id: string, asked_at: string, step: string, waiting_for: list<string>}|null
     */
    public function pending(string $topicKey, string $subjectId): ?array
    {
        $request = DB::table('approval_requests')->where('topic_key', $topicKey)->where('subject_id', $subjectId)->where('state', 'waiting')->first();

        if ($request === null) {
            return null;
        }

        $step = DB::table('approval_steps')->where('request_id', Row::text($request, 'id'))->where('state', 'waiting')->first(['label', 'asked']);

        return [
            'id' => Row::text($request, 'id'), 'asked_by' => $this->name(Row::text($request, 'requester_id')), 'asked_by_id' => Row::text($request, 'requester_id'),
            'asked_at' => Row::text($request, 'created_at'), 'step' => $step !== null ? Row::text($step, 'label') : '',
            'waiting_for' => $step !== null ? array_map(fn (string $u): string => $this->name($u), self::ids(Row::text($step, 'asked'))) : [],
        ];
    }

    /** The request of a record still waiting, if any: for the module's own screens. */
    public function waitingOf(string $topicKey, string $subjectId): ?string
    {
        $id = DB::table('approval_requests')->where('topic_key', $topicKey)->where('subject_id', $subjectId)->where('state', 'waiting')->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * The catalogue: every kind of request, grouped by area, with its chain.
     *
     * @return array{areas: list<array{area: string, topics: list<array{key: string, title: string, description: string, steps: list<string>, changed: bool, optional: bool}>}>, can_edit: bool}
     */
    public function catalogue(User $viewer): array
    {
        $this->access->check($viewer, AccessPermission::ManageApprovals);
        $areas = [];

        foreach ($this->approvals->topics() as $topic) {
            $chain = $this->approvals->chain($topic);
            $areas[$topic->area()][] = ['key' => $topic->key(), 'title' => $topic->title(), 'description' => $topic->description(),
                'steps' => array_map(fn (array $s): string => $this->approvals->label($topic, $s), $chain['steps']), 'changed' => $chain['changed'],
                'optional' => $topic instanceof OptionalApproval];
        }

        return ['areas' => array_map(fn (string $area, array $topics): array => ['area' => $area, 'topics' => $topics], array_keys($areas), array_values($areas)),
            'can_edit' => true];
    }

    /**
     * One kind of request and its chain, with every choice the editor offers.
     *
     * @return array{key: string, title: string, area: string, description: string, permission: string, optional: bool, chain: array{steps: list<Spec>, fallback_role_id: string|null, changed: bool, saved_by: string|null, saved_at: string|null}, labels: list<string>, relations: list<array{key: string, label: string}>, facts: list<array{key: string, label: string}>, roles: list<array{id: string, name: string}>, people: list<array{id: string, name: string}>, can_edit: bool}
     */
    public function topic(User $viewer, string $key): array
    {
        $this->access->check($viewer, AccessPermission::ManageApprovals);
        $topic = $this->approvals->topic($key) ?? throw new NotFoundHttpException;
        $chain = $this->approvals->chain($topic);

        return [
            'key' => $topic->key(), 'title' => $topic->title(), 'area' => $topic->area(), 'description' => $topic->description(),
            'permission' => $topic->permission()->label(),
            'optional' => $topic instanceof OptionalApproval,
            'chain' => [...$chain, 'saved_by' => $chain['saved_by'] !== null ? $this->name($chain['saved_by']) : null],
            'labels' => array_map(fn (array $s): string => $this->approvals->label($topic, $s), $chain['steps']),
            'relations' => array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l], array_keys($topic->relations()), array_values($topic->relations())),
            'facts' => array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l], array_keys($topic->facts()), array_values($topic->facts())),
            'roles' => array_values(array_filter($this->approvals->roles(), fn (array $r): bool => Role::query()->whereKey($r['id'])->first()?->is_system !== true)),
            'people' => $this->approvals->people($topic),
            'can_edit' => true,
        ];
    }

    private function name(string $userId): string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) ? $name : 'A former member of staff';
    }

    /** @return list<string> */
    private static function ids(string $json): array
    {
        $ids = json_decode($json, true);

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }
}
