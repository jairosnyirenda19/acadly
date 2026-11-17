<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals\Actions;

use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\Approvals;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Approvals\ApprovalWaiting;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Asks for an approval and records the answers (the approved Approvals
 * design). A request takes the chain in force when it is asked, and keeps
 * it. Steps are asked one at a time, in order. A no at any step ends the
 * request; a yes at the last step ends it too. Either way the module's own
 * decision is then performed, as the person who answered.
 *
 * @phpstan-import-type Spec from Approvals
 */
final class ApprovalActions
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    /** Opens the request for a record, or gives back the one already waiting. */
    public function open(string $topicKey, string $subjectId): string
    {
        $topic = $this->approvals->topic($topicKey) ?? throw new NotFoundHttpException;
        $case = $topic->case($subjectId) ?? throw new NotFoundHttpException;

        $waiting = DB::table('approval_requests')->where('topic_key', $topicKey)->where('subject_id', $subjectId)->where('state', 'waiting')->value('id');

        if (is_string($waiting)) {
            return $waiting;
        }

        $chain = $this->approvals->chain($topic);
        $id = Ids::next();
        $now = $this->clock->now();

        DB::transaction(function () use ($topic, $case, $chain, $id, $now, $subjectId): void {
            DB::table('approval_requests')->insert([
                'id' => $id, 'topic_key' => $topic->key(), 'subject_id' => $subjectId, 'requester_id' => $case->requesterId, 'state' => 'waiting',
                'current' => 0, 'chain' => json_encode(['steps' => $chain['steps'], 'fallback_role_id' => $chain['fallback_role_id']], JSON_THROW_ON_ERROR), 'created_at' => $now,
            ]);

            foreach ($chain['steps'] as $i => $spec) {
                DB::table('approval_steps')->insert(['id' => Ids::next(), 'request_id' => $id, 'position' => $i + 1, 'label' => mb_substr($this->approvals->label($topic, $spec), 0, 120),
                    'needs' => $spec['needs'], 'state' => 'pending', 'asked' => '[]']);
            }

            $this->audit->record(KernelAudit::ApprovalAsked, at: $case->at, meta: ['topic' => $topic->key(), 'subject' => $subjectId, 'request' => $id]);
            $this->advance($topic, $case, $id);
        });

        return $id;
    }

    /** Records a yes or a no from someone a waiting step asks. */
    public function answer(User $by, string $requestId, bool $approve, ?string $reason): void
    {
        $request = DB::table('approval_requests')->where('id', $requestId)->first();
        $topic = $request !== null ? $this->approvals->topic(Row::text($request, 'topic_key')) : null;

        if ($request === null || $topic === null) {
            throw new NotFoundHttpException;
        }

        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;

        if (! $approve && $reason === null) {
            throw ValidationException::withMessages(['reason' => ['Say why it is declined. The person who asked is told.']]);
        }

        DB::transaction(function () use ($by, $request, $topic, $approve, $reason, $requestId): void {
            $fresh = DB::table('approval_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($fresh === null || Row::text($fresh, 'state') !== 'waiting') {
                throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
            }

            // Nobody approves their own request: the same refusal as a journey's four eyes.
            if ($by->id === Row::text($fresh, 'requester_id')) {
                throw Problem::anotherPersonRequired();
            }

            $step = DB::table('approval_steps')->where('request_id', $requestId)->where('state', 'waiting')->orderBy('position')->first();
            $asked = $step !== null ? self::ids(Row::text($step, 'asked')) : [];

            if ($step === null || ! in_array($by->id, $asked, true)) {
                throw new AuthorizationException($step !== null ? 'This step is waiting for someone else: '.Row::text($step, 'label').'.' : 'Nothing is waiting for an answer.');
            }

            if (DB::table('approval_answers')->where('step_id', Row::text($step, 'id'))->where('user_id', $by->id)->exists()) {
                throw ValidationException::withMessages(['request' => ['You have already answered this step.']]);
            }

            $subjectId = Row::text($request, 'subject_id');
            $case = $topic->case($subjectId) ?? throw new NotFoundHttpException;
            $now = $this->clock->now();
            DB::table('approval_answers')->insert(['id' => Ids::next(), 'step_id' => Row::text($step, 'id'), 'user_id' => $by->id,
                'answer' => $approve ? 'approved' : 'declined', 'reason' => $reason, 'created_at' => $now]);
            $this->audit->record(KernelAudit::ApprovalAnswered, at: $case->at, actor: $this->audit->actorFor($by), reason: $reason,
                meta: ['topic' => $topic->key(), 'subject' => $subjectId, 'request' => $requestId, 'step' => Row::int($step, 'position'), 'answer' => $approve ? 'approved' : 'declined']);

            if (! $approve) {
                DB::table('approval_steps')->where('id', Row::text($step, 'id'))->update(['state' => 'declined', 'finished_at' => $now]);
                DB::table('approval_requests')->where('id', $requestId)->update(['state' => 'declined', 'decided_at' => $now]);
                $topic->settle($by, $subjectId, false, $reason);

                return;
            }

            $yes = DB::table('approval_answers')->where('step_id', Row::text($step, 'id'))->where('answer', 'approved')->count();

            if ($yes < Row::int($step, 'needs')) {
                return; // The step needs more people to agree.
            }

            DB::table('approval_steps')->where('id', Row::text($step, 'id'))->update(['state' => 'approved', 'finished_at' => $now]);

            if (! $this->advance($topic, $case, $requestId)) {
                DB::table('approval_requests')->where('id', $requestId)->update(['state' => 'approved', 'decided_at' => $now]);
                $topic->settle($by, $subjectId, true, $reason);
            }
        });
    }

    /**
     * Takes back a request still waiting: by the person who asked, or by the
     * module when what was asked no longer holds (it says why). Nothing changes.
     */
    public function withdraw(?User $by, string $topicKey, string $subjectId, string $why): bool
    {
        $request = DB::table('approval_requests')->where('topic_key', $topicKey)->where('subject_id', $subjectId)->where('state', 'waiting')->first();

        if ($request === null) {
            return false;
        }

        if ($by !== null && $by->id !== Row::text($request, 'requester_id')) {
            throw new AuthorizationException('Only the person who asked can withdraw a request.');
        }

        $topic = $this->approvals->topic($topicKey) ?? throw new NotFoundHttpException;
        $case = $topic->case($subjectId);
        $now = $this->clock->now();

        DB::transaction(function () use ($request, $by, $topic, $case, $subjectId, $why, $now): void {
            $id = Row::text($request, 'id');
            DB::table('approval_steps')->where('request_id', $id)->whereIn('state', ['waiting', 'pending'])->update(['state' => 'withdrawn', 'finished_at' => $now]);
            DB::table('approval_requests')->where('id', $id)->update(['state' => 'withdrawn', 'decided_at' => $now]);
            $this->audit->record(KernelAudit::ApprovalWithdrawn, at: $case?->at, actor: $by !== null ? $this->audit->actorFor($by) : null, reason: mb_substr($why, 0, 255),
                meta: ['topic' => $topic->key(), 'subject' => $subjectId, 'request' => $id]);
        });

        return true;
    }

    /**
     * Asks the next step that applies. False when no step is left.
     */
    private function advance(ApprovalTopic $topic, ApprovalCase $case, string $requestId): bool
    {
        $request = DB::table('approval_requests')->where('id', $requestId)->first();
        $chain = json_decode($request !== null ? Row::text($request, 'chain') : '{}', true);
        $specs = is_array($chain) && is_array($chain['steps'] ?? null) ? array_values(array_map(Approvals::spec(...), array_filter($chain['steps'], 'is_array'))) : [];
        $fallback = is_array($chain) && is_string($chain['fallback_role_id'] ?? null) ? $chain['fallback_role_id'] : null;
        $now = $this->clock->now();

        $pending = DB::table('approval_steps')->where('request_id', $requestId)->where('state', 'pending')->orderBy('position')->get();
        $applied = DB::table('approval_steps')->where('request_id', $requestId)->whereIn('state', ['approved', 'waiting'])->exists();

        foreach ($pending as $i => $step) {
            $spec = $specs[Row::int($step, 'position') - 1] ?? Approvals::anyone();
            $last = $i === $pending->count() - 1;

            // A step that applies only when a fact holds is skipped when it does not, and says so;
            // but a chain is never decided by nobody: when every step was skipped, the last one is asked.
            if ($spec['when'] !== null && ($case->facts[$spec['when']] ?? false) !== true && ! ($last && ! $applied)) {
                DB::table('approval_steps')->where('id', Row::text($step, 'id'))->update(['state' => 'skipped', 'finished_at' => $now,
                    'note' => mb_substr('Only when '.($topic->facts()[$spec['when']] ?? $spec['when']).': it does not.', 0, 255)]);

                continue;
            }

            $already = DB::table('approval_answers as a')->join('approval_steps as s', 's.id', '=', 'a.step_id')
                ->where('s.request_id', $requestId)->where('a.answer', 'approved')->pluck('a.user_id')->all();
            $to = $this->approvals->resolve($topic, $case, $spec, $fallback, array_values(array_filter($already, is_string(...))));
            DB::table('approval_steps')->where('id', Row::text($step, 'id'))->update([
                'state' => 'waiting', 'asked' => json_encode($to['asked'], JSON_THROW_ON_ERROR), 'asked_at' => $now,
                'needs' => max(1, min(Row::int($step, 'needs'), max(1, count($to['asked'])))), 'note' => $to['note'],
            ]);
            DB::table('approval_requests')->where('id', $requestId)->update(['current' => Row::int($step, 'position')]);
            $this->notifier->send(new ApprovalWaiting($requestId, Row::int($step, 'position')));

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private static function ids(string $json): array
    {
        $ids = json_decode($json, true);

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }
}
