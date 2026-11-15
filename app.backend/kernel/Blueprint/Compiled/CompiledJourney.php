<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use Acadly\Kernel\Blueprint\BlueprintKind;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Canonical;
use InvalidArgumentException;
use LogicException;

/**
 * A journey blueprint in its compiled form: what the engine runs, what is
 * hashed into the lock, and what the projections read. Made by
 * `JourneyBlueprint::compile()` or read back from the canonical JSON.
 */
final readonly class CompiledJourney implements CompiledBlueprint
{
    /**
     * @param  array<string, string>  $states  value => label, in declared order
     * @param  list<string>  $terminal
     * @param  array<string, list<string>>  $milestones  milestone => states that reach it
     * @param  list<CompiledStage>  $stages
     * @param  array<string, CompiledStep>  $steps  in declared order
     */
    public function __construct(
        public BlueprintRef $ref,
        public string $title,
        public string $subject,
        public array $states,
        public string $initial,
        public array $terminal,
        public array $milestones,
        public array $stages,
        public array $steps,
    ) {}

    public function reference(): BlueprintRef
    {
        return $this->ref;
    }

    public function step(string $id): CompiledStep
    {
        return $this->steps[$id] ?? throw new LogicException("Journey [{$this->ref}] has no step [{$id}].");
    }

    public function hasStep(string $id): bool
    {
        return isset($this->steps[$id]);
    }

    /** @return list<CompiledStep> the steps that can start from this state */
    public function stepsFrom(string $state): array
    {
        return array_values(array_filter($this->steps, fn (CompiledStep $step): bool => in_array($state, $step->from, true)));
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, $this->terminal, true);
    }

    public function label(string $state): string
    {
        return $this->states[$state] ?? $state;
    }

    public function hash(): string
    {
        return Canonical::hash($this->toArray());
    }

    public function json(): string
    {
        return Canonical::json($this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => BlueprintKind::Journey->value,
            'ref' => (string) $this->ref,
            'title' => $this->title,
            'subject' => $this->subject,
            'states' => array_map(
                fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
                array_keys($this->states),
                array_values($this->states),
            ),
            'initial' => $this->initial,
            'terminal' => $this->terminal,
            'milestones' => $this->milestones,
            'stages' => array_map(fn (CompiledStage $stage): array => $stage->toArray(), $this->stages),
            'steps' => array_values(array_map(fn (CompiledStep $step): array => $step->toArray(), $this->steps)),
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        if (($data['kind'] ?? null) !== BlueprintKind::Journey->value) {
            throw new InvalidArgumentException('The compiled blueprint is not a journey.');
        }

        $states = [];

        foreach (Shape::records($data, 'states') as $state) {
            $states[Shape::string($state, 'id')] = Shape::string($state, 'label');
        }

        $steps = [];

        foreach (Shape::records($data, 'steps') as $step) {
            $compiled = CompiledStep::fromArray($step);
            $steps[$compiled->id] = $compiled;
        }

        return new self(
            BlueprintRef::parse(Shape::string($data, 'ref')),
            Shape::string($data, 'title'),
            Shape::string($data, 'subject'),
            $states,
            Shape::string($data, 'initial'),
            Shape::strings($data, 'terminal'),
            Shape::groups($data, 'milestones'),
            array_map(CompiledStage::fromArray(...), Shape::records($data, 'stages')),
            $steps,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return self::fromArray(is_array($data) ? $data : throw new InvalidArgumentException('The compiled blueprint is not an object.'));
    }
}
