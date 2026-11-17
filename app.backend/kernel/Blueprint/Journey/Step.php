<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Access\Permission;
use BackedEnum;

/**
 * One step of a journey. It answers the four questions in order, never fused:
 * gates (can it happen), capability and structural checks (may you), effects
 * (what follows), transition (what is now true).
 */
final class Step
{
    private string $title = '';

    /** @var list<JourneyState> */
    private array $from = [];

    /** @var list<array{class: class-string<Gate>, args: ?GateArgs}> */
    private array $gates = [];

    private ?Permission $capability = null;

    private ?BackedEnum $fourEyes = null;

    private bool $holder = false;

    /** @var list<Outcome> */
    private array $outcomes = [];

    private function __construct(public readonly BackedEnum $id) {}

    public static function make(BackedEnum $id): self
    {
        return new self($id);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function from(JourneyState ...$states): self
    {
        $this->from = [...$this->from, ...array_values($states)];

        return $this;
    }

    /** @param  class-string<Gate>  $gate */
    public function gate(string $gate, ?GateArgs $args = null): self
    {
        $this->gates[] = ['class' => $gate, 'args' => $args];

        return $this;
    }

    /** The permission the person needs, checked at the record's scope. */
    public function capability(Permission $permission): self
    {
        $this->capability = $permission;

        return $this;
    }

    /**
     * Taken by the account the record belongs to, with no permission
     * (`HeldRecord`). Nobody else may take it.
     */
    public function byHolder(): self
    {
        $this->holder = true;

        return $this;
    }

    /** Four eyes: the person who performed that step on this record may not perform this one. */
    public function fourEyes(BackedEnum $otherStep): self
    {
        $this->fourEyes = $otherStep;

        return $this;
    }

    public function outcome(Outcome $outcome): self
    {
        $this->outcomes[] = $outcome;

        return $this;
    }

    public function titleText(): string
    {
        return $this->title;
    }

    /** @return list<JourneyState> */
    public function fromStates(): array
    {
        return $this->from;
    }

    /** @return list<array{class: class-string<Gate>, args: ?GateArgs}> */
    public function gates(): array
    {
        return $this->gates;
    }

    public function permission(): ?Permission
    {
        return $this->capability;
    }

    public function takenByHolder(): bool
    {
        return $this->holder;
    }

    public function fourEyesStep(): ?BackedEnum
    {
        return $this->fourEyes;
    }

    /** @return list<Outcome> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }
}
