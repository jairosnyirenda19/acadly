<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Blueprint\BlueprintKind;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Compiled\CompiledCall;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Compiled\CompiledOutcome;
use Acadly\Kernel\Blueprint\Compiled\CompiledStage;
use Acadly\Kernel\Blueprint\Compiled\CompiledStep;
use BackedEnum;
use Closure;
use InvalidArgumentException;

/**
 * Declares how a record moves (docs/architecture/02-blueprints.md §3).
 *
 * Authored as typed PHP in a module's `Journeys/<Name>/V<YYYY>_<N>.php`,
 * which returns one of these. States, steps and outcomes are enum cases;
 * gates, effects and events are class names. There is no `if` in a
 * declaration. `compile()` turns it into the canonical form the engine runs.
 */
final class JourneyBlueprint
{
    private string $title = '';

    private ?string $subject = null;

    /** @var class-string<JourneyState>|null */
    private ?string $statesEnum = null;

    private ?JourneyState $initial = null;

    /** @var list<JourneyState> */
    private array $terminal = [];

    /** @var array<string, list<JourneyState>> */
    private array $milestones = [];

    /** @var list<Stage> */
    private array $stages = [];

    private function __construct(public readonly BlueprintRef $ref) {}

    public static function make(string $ref): self
    {
        $parsed = BlueprintRef::parse($ref);

        if ($parsed->kind() !== BlueprintKind::Journey) {
            throw new InvalidArgumentException("[{$ref}] is not a journey reference.");
        }

        return new self($parsed);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @param  class-string<JourneySubject>  $subject */
    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @param  class-string<JourneyState>  $enum
     * @param  list<JourneyState>  $terminal
     */
    public function states(string $enum, JourneyState $initial, array $terminal = []): self
    {
        $this->statesEnum = $enum;
        $this->initial = $initial;
        $this->terminal = $terminal;

        return $this;
    }

    /** A named point other code can ask about: `hasReached(Milestone::Decided)`. */
    public function milestone(BackedEnum $milestone, JourneyState ...$states): self
    {
        $this->milestones[self::value($milestone)] = array_values($states);

        return $this;
    }

    /** @param  Closure(Stage): Stage  $build */
    public function stage(string $key, string $title, Closure $build): self
    {
        $this->stages[] = $build(new Stage($key, $title));

        return $this;
    }

    /**
     * The canonical form. Refuses a declaration whose parts do not fit
     * together; the linter then checks what the parts mean.
     *
     * @throws InvalidArgumentException
     */
    public function compile(): CompiledJourney
    {
        $where = (string) $this->ref;
        $enum = $this->statesEnum ?? throw new InvalidArgumentException("{$where}: the states are not declared.");
        $subject = $this->subject ?? throw new InvalidArgumentException("{$where}: the subject is not declared.");
        $initial = $this->initial ?? throw new InvalidArgumentException("{$where}: the initial state is not declared.");

        if (trim($this->title) === '') {
            throw new InvalidArgumentException("{$where}: the journey needs a title.");
        }

        $states = [];

        foreach ($enum::cases() as $case) {
            $states[self::value($case)] = $case->label();
        }

        $state = function (JourneyState $case) use ($enum, $where): string {
            if (! $case instanceof $enum) {
                throw new InvalidArgumentException("{$where}: a state of another journey is used.");
            }

            return self::value($case);
        };

        $steps = [];
        $stages = [];

        foreach ($this->stages as $stage) {
            $ids = [];

            foreach ($stage->steps() as $step) {
                $compiled = $this->compileStep($step, $stage, $state);

                if (isset($steps[$compiled->id])) {
                    throw new InvalidArgumentException("{$where}: step [{$compiled->id}] is declared twice.");
                }

                $steps[$compiled->id] = $compiled;
                $ids[] = $compiled->id;
            }

            $stages[] = new CompiledStage($stage->key, $stage->title, $ids);
        }

        $milestones = [];

        foreach ($this->milestones as $name => $reached) {
            $milestones[$name] = array_map($state, $reached);
        }

        return new CompiledJourney(
            $this->ref,
            $this->title,
            $subject,
            $states,
            $state($initial),
            array_map($state, $this->terminal),
            $milestones,
            $stages,
            $steps,
        );
    }

    /** @param  Closure(JourneyState): string  $state */
    private function compileStep(Step $step, Stage $stage, Closure $state): CompiledStep
    {
        $id = self::value($step->id);
        $outcomes = [];

        foreach ($step->outcomes() as $outcome) {
            $outcomeId = self::value($outcome->id);
            $transition = $outcome->transitionTo()
                ?? throw new InvalidArgumentException("{$this->ref}: outcome [{$id}.{$outcomeId}] has no transition.");

            if (isset($outcomes[$outcomeId])) {
                throw new InvalidArgumentException("{$this->ref}: outcome [{$id}.{$outcomeId}] is declared twice.");
            }

            $outcomes[$outcomeId] = new CompiledOutcome(
                $outcomeId,
                $outcome->titleText(),
                $outcome->needsReason(),
                array_map(
                    fn (array $effect): CompiledCall => new CompiledCall($effect['class'], $effect['args'] !== null ? $effect['args']::class : null, $effect['args']?->toArray() ?? []),
                    $outcome->effects(),
                ),
                $state($transition->to),
                $transition->events(),
            );
        }

        $fourEyes = $step->fourEyesStep();

        return new CompiledStep(
            $id,
            $step->titleText(),
            $stage->key,
            array_map($state, $step->fromStates()),
            array_map(
                fn (array $gate): CompiledCall => new CompiledCall($gate['class'], $gate['args'] !== null ? $gate['args']::class : null, $gate['args']?->toArray() ?? []),
                $step->gates(),
            ),
            $step->permission()?->key(),
            $fourEyes !== null ? self::value($fourEyes) : null,
            $outcomes,
        );
    }

    private static function value(BackedEnum $case): string
    {
        return is_string($case->value) ? $case->value : throw new InvalidArgumentException('Journey states, steps, outcomes and milestones are string-backed enums.');
    }
}
