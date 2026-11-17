<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Engine;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\JourneyLine;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome as AuditOutcome;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Compiled\CompiledCall;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Compiled\CompiledStep;
use Acadly\Kernel\Blueprint\Engine\Jobs\ApplyAfterCommitEffect;
use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\HeldRecord;
use Acadly\Kernel\Blueprint\Journey\JourneyColumns;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Blueprint\Journey\JourneyEvent;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use BackedEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The one engine for every journey and every kind of record
 * (docs/architecture/02-blueprints.md §3.4). It names no module.
 *
 * `perform()` is the only way a journey state changes. It answers the four
 * questions in order:
 *  1. Admissibility: the gates. Closed → `step_not_admissible`, with reason and remedy.
 *  2. Authority: `Access::check()` at the record's scope, then four eyes.
 *  3. Consequence: internal effects in order, inside the transaction, each
 *     applied once under its idempotency key.
 *  4. Commitment: the new state (through the guarded column), the step
 *     completion, the outbox events and the audit line, in the same
 *     transaction. After commit, the after-commit effects go to the queue.
 */
final class JourneyEngine
{
    public function __construct(
        private readonly Blueprints $blueprints,
        private readonly Access $access,
        private readonly PermissionCatalogue $permissions,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
        private readonly Container $container,
    ) {}

    /**
     * Read-only. What this person may do to this record now, and if a step is
     * blocked, why.
     *
     * @return list<StepAvailability>
     */
    public function available(JourneySubject $subject, ?User $who): array
    {
        $journey = $this->journeyOf($subject);
        $out = [];

        foreach ($journey->stepsFrom($subject->journeyState()) as $step) {
            $context = $this->context($subject, $who, $journey->ref, $step->id, null, []);
            $blocked = $this->firstClosedGate($step, $context);
            $permitted = $who !== null
                && ($step->holder ? self::holds($subject, $who) : $this->access->allows($who, $this->permission($step), $subject->journeyScope()))
                && ! $this->fourEyesRefuses($subject, $step, $who);

            $out[] = new StepAvailability(
                $step->id,
                $step->title,
                array_values(array_map(
                    fn ($outcome): array => ['outcome' => $outcome->id, 'title' => $outcome->title, 'requires_reason' => $outcome->requiresReason],
                    $step->outcomes,
                )),
                $permitted,
                $blocked?->reason,
                $blocked?->remedy,
            );
        }

        return $out;
    }

    /**
     * The single mutating entry point for journey state.
     *
     * @param  array<string, mixed>  $input  what the person submitted with the step
     */
    public function perform(JourneySubject $subject, User $who, BackedEnum|string $step, BackedEnum|string $outcome, array $input = []): Receipt
    {
        $journey = $this->journeyOf($subject);
        $stepId = self::id($step);
        $outcomeId = self::id($outcome);
        $from = $subject->journeyState();

        if (! $journey->hasStep($stepId) || ! $journey->step($stepId)->hasOutcome($outcomeId)) {
            throw Problem::stepNotAvailable();
        }

        $compiledStep = $journey->step($stepId);
        $compiledOutcome = $compiledStep->outcome($outcomeId);

        if (! in_array($from, $compiledStep->from, true)) {
            throw Problem::stepNotAvailable();
        }

        $context = $this->context($subject, $who, $journey->ref, $stepId, $outcomeId, $input);
        $reason = $context->text('reason');

        if ($compiledOutcome->requiresReason && $reason === null) {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        // 1. Admissibility.
        $this->assertGatesOpen($compiledStep, $context);

        // 2. Authority. A refusal is audited by Access, outside the step's transaction.
        $scope = $subject->journeyScope();

        if ($compiledStep->holder) {
            if (! self::holds($subject, $who)) {
                $this->audit->record(KernelAudit::Refused, subject: $subject, outcome: AuditOutcome::Refused,
                    refusal: 'not_holder', actor: $this->audit->actorFor($who), at: $scope, meta: ['step' => $stepId]);

                throw new AuthorizationException('Access is refused.');
            }
        } else {
            $this->access->check($who, $this->permission($compiledStep), $scope);
        }

        if ($this->fourEyesRefuses($subject, $compiledStep, $who)) {
            $this->audit->record(KernelAudit::Refused, subject: $subject, outcome: AuditOutcome::Refused,
                refusal: 'four_eyes:'.$compiledStep->fourEyes, actor: $this->audit->actorFor($who), at: $scope,
                meta: ['step' => $stepId]);

            throw Problem::anotherPersonRequired();
        }

        // 3 and 4, in one transaction.
        $receipt = DB::transaction(function () use ($subject, $who, $journey, $compiledStep, $compiledOutcome, $context, $from, $reason, $input, $scope): Receipt {
            $this->assertUnchanged($subject, $journey->ref, $from);
            $this->assertGatesOpen($compiledStep, $context);

            $seq = $this->nextSeq($subject);
            $completionId = Ids::next();
            $later = [];

            foreach ($compiledOutcome->effects as $index => $call) {
                $effect = $this->effect($call);
                $key = self::idempotencyKey($subject, $journey->ref, $compiledStep->id, $compiledOutcome->id, $seq, $index);

                if ($effect->boundary() === Boundary::AfterCommit) {
                    $later[] = [$index, $key];

                    continue;
                }

                $result = $this->applyOnce($effect, $call, $context, $key, $subject, $completionId);

                if ($result->produced !== null) {
                    $context = $context->withProduced($effect::class, $result->produced);
                }
            }

            $to = $compiledOutcome->to;
            $this->writeState($subject, $to);

            DB::table('journey_step_completions')->insert([
                'id' => $completionId,
                'subject_type' => $subject->auditType(),
                'subject_id' => $subject->auditId(),
                'seq' => $seq,
                'journey_ref' => (string) $journey->ref,
                'step' => $compiledStep->id,
                'outcome' => $compiledOutcome->id,
                'from_state' => $from,
                'to_state' => $to,
                'actor_user_id' => $who->id,
                'input' => json_encode((object) $input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'reason' => $reason,
                'occurred_at' => $this->clock->now(),
            ]);

            foreach ($compiledOutcome->emits as $event) {
                if (! is_subclass_of($event, JourneyEvent::class)) {
                    throw new LogicException("[{$event}] is not a journey event.");
                }

                $this->outbox->record($event::fromJourney($context, $to));
            }

            $this->audit->record(KernelAudit::StepPerformed, subject: $subject, reason: $reason,
                actor: $this->audit->actorFor($who), at: $scope,
                meta: ['from' => $from, 'to' => $to],
                journey: new JourneyLine((string) $journey->ref, $compiledStep->id, $compiledOutcome->id));

            foreach ($later as [$index, $key]) {
                $job = new ApplyAfterCommitEffect((string) $journey->ref, $subject->auditId(), $who->id, $compiledStep->id,
                    $compiledOutcome->id, $from, $index, $key, $completionId, $input, $context->produced);

                DB::afterCommit(fn () => dispatch($job));
            }

            return new Receipt($completionId, (string) $journey->ref, $compiledStep->id, $compiledOutcome->id, $from, $to, $context->produced);
        });

        $subject->journeyStateCommitted($receipt->toState);

        return $receipt;
    }

    /** Runs one after-commit effect from the queue. Once only, whatever the number of attempts. */
    public function applyAfterCommit(ApplyAfterCommitEffect $job): void
    {
        $journey = $this->blueprints->journey(BlueprintRef::parse($job->ref));
        $class = $journey->subject;

        if (! is_subclass_of($class, JourneySubject::class)) {
            throw new LogicException("[{$class}] is not a journey subject.");
        }

        $subject = $class::findJourneySubject($job->subjectId);

        if ($subject === null) {
            return;
        }

        $call = $journey->step($job->step)->outcome($job->outcome)->effects[$job->index]
            ?? throw new LogicException("The effect {$job->index} of [{$job->ref}] is not declared.");

        $actor = $job->actorId !== null ? User::query()->find($job->actorId) : null;
        $context = new JourneyContext($subject, $actor instanceof User ? $actor : null, $journey->ref, $job->step, $job->outcome,
            $job->fromState, $job->input, self::producedMap($job->produced));

        DB::transaction(fn () => $this->applyOnce($this->effect($call), $call, $context, $job->key, $subject, $job->completionId));
    }

    /** Whether the record has ever been in a state that reaches this milestone. */
    public function hasReached(JourneySubject $subject, BackedEnum $milestone): bool
    {
        $states = $this->journeyOf($subject)->milestones[self::id($milestone)] ?? [];

        if ($states === []) {
            return false;
        }

        if (in_array($subject->journeyState(), $states, true)) {
            return true;
        }

        return DB::table('journey_step_completions')
            ->where('subject_type', $subject->auditType())
            ->where('subject_id', $subject->auditId())
            ->whereIn('to_state', $states)
            ->exists();
    }

    /** The initial state of a journey version, for a module that makes a new record. */
    public function initialState(BlueprintRef $ref): string
    {
        return $this->blueprints->journey($ref)->initial;
    }

    private function journeyOf(JourneySubject $subject): CompiledJourney
    {
        $journey = $this->blueprints->journey($subject->journeyRef());

        if (! $subject instanceof $journey->subject) {
            throw new LogicException("[{$journey->ref}] does not apply to this kind of record.");
        }

        return $journey;
    }

    /** @param  array<string, mixed>  $input */
    private function context(JourneySubject $subject, ?User $who, BlueprintRef $ref, string $step, ?string $outcome, array $input): JourneyContext
    {
        return new JourneyContext($subject, $who, $ref, $step, $outcome, $subject->journeyState(), $input);
    }

    private function firstClosedGate(CompiledStep $step, JourneyContext $context): ?GateResult
    {
        foreach ($step->gates as $call) {
            $gate = $this->container->make($call->class);

            if (! $gate instanceof Gate) {
                throw new LogicException("[{$call->class}] is not a gate.");
            }

            $result = $gate->check($context, $this->gateArgs($call));

            if (! $result->passed) {
                return $result;
            }
        }

        return null;
    }

    private function assertGatesOpen(CompiledStep $step, JourneyContext $context): void
    {
        $closed = $this->firstClosedGate($step, $context);

        if ($closed !== null) {
            throw Problem::stepNotAdmissible($closed->reason ?? '', $closed->remedy ?? '');
        }
    }

    /** The record belongs to this account (`HeldRecord`). */
    private static function holds(JourneySubject $subject, User $who): bool
    {
        return $subject instanceof HeldRecord && $subject->heldBy() === $who->id;
    }

    private function permission(CompiledStep $step): Permission
    {
        return $this->permissions->get($step->capability ?? throw new LogicException("Step [{$step->id}] has no capability."));
    }

    private function fourEyesRefuses(JourneySubject $subject, CompiledStep $step, User $who): bool
    {
        if ($step->fourEyes === null) {
            return false;
        }

        $last = DB::table('journey_step_completions')
            ->where('subject_type', $subject->auditType())
            ->where('subject_id', $subject->auditId())
            ->where('step', $step->fourEyes)
            ->orderByDesc('seq')
            ->value('actor_user_id');

        return $last === $who->id;
    }

    /** The row is locked, and still in the state and version the request saw. */
    private function assertUnchanged(JourneySubject $subject, BlueprintRef $ref, string $from): void
    {
        $column = $subject->journeyStateColumn();
        $row = DB::table($subject->journeyTable())->where('id', $subject->auditId())->lockForUpdate()->first([$column, 'journey_ref']);
        $values = $row !== null ? (array) $row : [];

        if (($values[$column] ?? null) !== $from || ($values['journey_ref'] ?? null) !== (string) $ref) {
            throw Problem::stateChanged();
        }
    }

    private function writeState(JourneySubject $subject, string $to): void
    {
        DB::select('select set_config(?, ?, true)', [JourneyColumns::FLAG, 'on']);

        DB::table($subject->journeyTable())->where('id', $subject->auditId())->update([$subject->journeyStateColumn() => $to]);

        DB::select('select set_config(?, ?, true)', [JourneyColumns::FLAG, 'off']);
    }

    private function nextSeq(JourneySubject $subject): int
    {
        $max = DB::table('journey_step_completions')
            ->where('subject_type', $subject->auditType())
            ->where('subject_id', $subject->auditId())
            ->max('seq');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /** Applies an effect under its key, or returns what the earlier application produced. */
    private function applyOnce(Effect $effect, CompiledCall $call, JourneyContext $context, string $key, JourneySubject $subject, string $completionId): EffectResult
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);

        $done = DB::table('effect_applications')->where('idempotency_key', $key)->first(['produced']);

        if ($done !== null) {
            return new EffectResult(is_string($done->produced) ? $done->produced : null);
        }

        $result = $effect->apply($context, $this->effectArgs($call), $key);

        DB::table('effect_applications')->insert([
            'id' => Ids::next(),
            'idempotency_key' => $key,
            'effect' => $call->class,
            'boundary' => $effect->boundary()->value,
            'subject_type' => $subject->auditType(),
            'subject_id' => $subject->auditId(),
            'completion_id' => $completionId,
            'produced' => $result->produced,
            'meta' => json_encode((object) $result->meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'applied_at' => $this->clock->now(),
        ]);

        return $result;
    }

    private function effect(CompiledCall $call): Effect
    {
        $effect = $this->container->make($call->class);

        return $effect instanceof Effect ? $effect : throw new LogicException("[{$call->class}] is not an effect.");
    }

    private function gateArgs(CompiledCall $call): ?GateArgs
    {
        if ($call->argsClass === null) {
            return null;
        }

        return is_subclass_of($call->argsClass, GateArgs::class)
            ? $call->argsClass::fromArray($call->args)
            : throw new LogicException("[{$call->argsClass}] is not a gate argument.");
    }

    private function effectArgs(CompiledCall $call): ?EffectArgs
    {
        if ($call->argsClass === null) {
            return null;
        }

        return is_subclass_of($call->argsClass, EffectArgs::class)
            ? $call->argsClass::fromArray($call->args)
            : throw new LogicException("[{$call->argsClass}] is not an effect argument.");
    }

    /**
     * The same for every retry of the same step on the same record, and
     * different for each later visit to that step (`seq`), so a journey that
     * loops applies its effects again on the next round.
     */
    private static function idempotencyKey(JourneySubject $subject, BlueprintRef $ref, string $step, string $outcome, int $seq, int $index): string
    {
        return hash('sha256', implode('|', [$subject->auditType(), $subject->auditId(), (string) $ref, $step, $outcome, $seq, $index]));
    }

    /**
     * @param  array<string, string>  $produced
     * @return array<class-string<Effect>, string>
     */
    private static function producedMap(array $produced): array
    {
        $out = [];

        foreach ($produced as $class => $id) {
            if (is_subclass_of($class, Effect::class)) {
                $out[$class] = $id;
            }
        }

        return $out;
    }

    private static function id(BackedEnum|string $value): string
    {
        $id = $value instanceof BackedEnum ? $value->value : $value;

        return is_string($id) ? $id : throw new LogicException('Journey steps, outcomes and milestones are string-backed enums.');
    }
}
