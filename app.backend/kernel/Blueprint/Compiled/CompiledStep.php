<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use LogicException;

final readonly class CompiledStep
{
    /**
     * @param  list<string>  $from
     * @param  list<CompiledCall>  $gates
     * @param  array<string, CompiledOutcome>  $outcomes  in declared order
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $stage,
        public array $from,
        public array $gates,
        public ?string $capability,
        public ?string $fourEyes,
        public array $outcomes,
        public bool $holder = false,
    ) {}

    public function outcome(string $id): CompiledOutcome
    {
        return $this->outcomes[$id] ?? throw new LogicException("Step [{$this->id}] has no outcome [{$id}].");
    }

    public function hasOutcome(string $id): bool
    {
        return isset($this->outcomes[$id]);
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $outcomes = [];

        foreach (Shape::records($data, 'outcomes') as $outcome) {
            $compiled = CompiledOutcome::fromArray($outcome);
            $outcomes[$compiled->id] = $compiled;
        }

        return new self(
            Shape::string($data, 'id'),
            Shape::string($data, 'title'),
            Shape::string($data, 'stage'),
            Shape::strings($data, 'from'),
            array_map(CompiledCall::fromArray(...), Shape::records($data, 'gates')),
            Shape::nullableString($data, 'capability'),
            Shape::nullableString($data, 'four_eyes'),
            $outcomes,
            array_key_exists('holder', $data) && Shape::bool($data, 'holder'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // `holder` is written only when true, so blueprints released before it keep their hash.
        return [
            'id' => $this->id,
            'title' => $this->title,
            'stage' => $this->stage,
            'from' => $this->from,
            'gates' => array_map(fn (CompiledCall $call): array => $call->toArray(), $this->gates),
            'capability' => $this->capability,
            'four_eyes' => $this->fourEyes,
            'outcomes' => array_values(array_map(fn (CompiledOutcome $outcome): array => $outcome->toArray(), $this->outcomes)),
        ] + ($this->holder ? ['holder' => true] : []);
    }
}
