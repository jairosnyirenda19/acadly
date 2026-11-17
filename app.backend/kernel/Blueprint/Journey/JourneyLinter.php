<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Blueprint\Compiled\CompiledCall;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Notify\JourneyTopic;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\SendTopic;

/**
 * Checks what a compiled journey means (02 §3.7, §3.9): every part refers to
 * something real in this build, every state can be reached, every state
 * that is not terminal has a way out, and nothing is declared that the
 * engine would not run.
 */
final class JourneyLinter
{
    public function __construct(
        private readonly PermissionCatalogue $permissions,
        private readonly EventCatalogue $events,
        private readonly NoticeCatalogue $notices,
    ) {}

    /** @return list<string> */
    public function problems(CompiledJourney $journey): array
    {
        $at = (string) $journey->ref;
        $problems = [];

        if (! is_subclass_of($journey->subject, JourneySubject::class)) {
            $problems[] = "{$at}: the subject [{$journey->subject}] is not a journey subject.";
        }

        if ($journey->states === []) {
            $problems[] = "{$at}: the journey has no states.";
        }

        foreach ($journey->stages as $stage) {
            if ($stage->steps === []) {
                $problems[] = "{$at}: stage [{$stage->key}] has no steps.";
            }
        }

        foreach ($journey->steps as $step) {
            $where = "{$at} step [{$step->id}]";

            if (trim($step->title) === '') {
                $problems[] = "{$where}: the step needs a title.";
            }

            if ($step->from === []) {
                $problems[] = "{$where}: the step starts from no state.";
            }

            foreach ($step->from as $from) {
                if ($journey->isTerminal($from)) {
                    $problems[] = "{$where}: the step starts from the terminal state [{$from}].";
                }
            }

            if ($step->holder) {
                if ($step->capability !== null) {
                    $problems[] = "{$where}: a step taken by the holder needs no capability.";
                }
            } elseif ($step->capability === null) {
                $problems[] = "{$where}: the step needs a capability (the permission that allows it).";
            } elseif (! $this->permissions->has($step->capability)) {
                $problems[] = "{$where}: [{$step->capability}] is not a permission in this build.";
            }

            if ($step->fourEyes !== null && ! $journey->hasStep($step->fourEyes)) {
                $problems[] = "{$where}: four eyes refers to the unknown step [{$step->fourEyes}].";
            }

            foreach ($step->gates as $gate) {
                $problems = [...$problems, ...$this->call($where, 'gate', $gate, Gate::class, GateArgs::class)];
            }

            if ($step->outcomes === []) {
                $problems[] = "{$where}: the step has no outcome.";
            }

            foreach ($step->outcomes as $outcome) {
                $here = "{$where} outcome [{$outcome->id}]";

                if (trim($outcome->title) === '') {
                    $problems[] = "{$here}: the outcome needs a title.";
                }

                foreach ($outcome->effects as $effect) {
                    $problems = [...$problems, ...$this->call($here, 'effect', $effect, Effect::class, EffectArgs::class)];

                    if ($effect->class === SendTopic::class) {
                        $topic = $effect->args['topic'] ?? null;

                        if (! is_string($topic) || ! is_subclass_of($topic, JourneyTopic::class)) {
                            $problems[] = "{$here}: the notice is not a journey topic.";
                        } elseif (! $this->notices->has($topic::notice()->key)) {
                            $problems[] = "{$here}: the notice [{$topic::notice()->key}] is not listed by its module.";
                        }
                    }
                }

                foreach ($outcome->emits as $event) {
                    if (! is_subclass_of($event, JourneyEvent::class)) {
                        $problems[] = "{$here}: [{$event}] is not a journey event.";
                    } elseif (! $this->events->has($event::eventName())) {
                        $problems[] = "{$here}: the event [{$event::eventName()}] is not listed by its module.";
                    }
                }
            }
        }

        return [...$problems, ...$this->reachability($journey)];
    }

    /**
     * @param  class-string  $contract
     * @param  class-string  $argsContract
     * @return list<string>
     */
    private function call(string $where, string $what, CompiledCall $call, string $contract, string $argsContract): array
    {
        $problems = [];

        if (! is_subclass_of($call->class, $contract)) {
            $problems[] = "{$where}: the {$what} [{$call->class}] does not implement ".class_basename($contract).'.';
        }

        if ($call->argsClass !== null && ! is_subclass_of($call->argsClass, $argsContract)) {
            $problems[] = "{$where}: the arguments [{$call->argsClass}] do not implement ".class_basename($argsContract).'.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function reachability(CompiledJourney $journey): array
    {
        $at = (string) $journey->ref;
        $reached = [$journey->initial => true];
        $queue = [$journey->initial];

        while ($queue !== []) {
            $state = array_shift($queue);

            foreach ($journey->stepsFrom($state) as $step) {
                foreach ($step->outcomes as $outcome) {
                    if (! isset($reached[$outcome->to])) {
                        $reached[$outcome->to] = true;
                        $queue[] = $outcome->to;
                    }
                }
            }
        }

        $problems = [];

        foreach (array_keys($journey->states) as $state) {
            if (! isset($reached[$state])) {
                $problems[] = "{$at}: the state [{$state}] cannot be reached from [{$journey->initial}].";
            }

            if (! $journey->isTerminal($state) && $journey->stepsFrom($state) === []) {
                $problems[] = "{$at}: the state [{$state}] is not terminal and has no step out of it.";
            }
        }

        return $problems;
    }
}
