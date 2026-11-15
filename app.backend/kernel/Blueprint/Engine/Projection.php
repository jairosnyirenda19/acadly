<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Engine;

use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Identity\User;

/**
 * Read-only views of a journey (02 §3.4): the state label, the progress
 * ladder, the actions a person can take, and a Mermaid diagram for the
 * documentation. Resources of journey subjects are built from `present()`
 * (05 §6), so a screen never compares state values itself.
 */
final class Projection
{
    public function __construct(
        private readonly Blueprints $blueprints,
        private readonly JourneyEngine $engine,
    ) {}

    /**
     * @return array{
     *     state: string,
     *     state_label: string,
     *     ladder: list<array{stage: string, title: string, current: bool}>,
     *     available_actions: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: ?string, remedy: ?string}>
     * }
     */
    public function present(JourneySubject $subject, ?User $who): array
    {
        $journey = $this->blueprints->journey($subject->journeyRef());
        $state = $subject->journeyState();

        return [
            'state' => $state,
            'state_label' => $journey->label($state),
            'ladder' => $this->ladder($journey, $state),
            'available_actions' => array_map(fn (StepAvailability $step): array => $step->toArray(), $this->engine->available($subject, $who)),
        ];
    }

    /**
     * The stages in order. The current stage holds a step that starts from the state.
     *
     * @return list<array{stage: string, title: string, current: bool}>
     */
    public function ladder(CompiledJourney $journey, string $state): array
    {
        return array_map(
            fn ($stage): array => [
                'stage' => $stage->key,
                'title' => $stage->title,
                'current' => array_filter($stage->steps, fn (string $step): bool => in_array($state, $journey->step($step)->from, true)) !== [],
            ],
            $journey->stages,
        );
    }

    /** A Mermaid state diagram: every state, and every outcome as an arrow. */
    public function diagram(CompiledJourney $journey): string
    {
        $lines = ['stateDiagram-v2', "    [*] --> {$journey->initial}"];

        foreach ($journey->states as $state => $label) {
            $lines[] = "    {$state} : ".str_replace(':', ' ', $label);
        }

        foreach ($journey->steps as $step) {
            foreach ($step->from as $from) {
                foreach ($step->outcomes as $outcome) {
                    $lines[] = "    {$from} --> {$outcome->to} : {$step->id} · {$outcome->id}";
                }
            }
        }

        foreach ($journey->terminal as $state) {
            $lines[] = "    {$state} --> [*]";
        }

        return implode("\n", $lines)."\n";
    }
}
