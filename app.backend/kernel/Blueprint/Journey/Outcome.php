<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use BackedEnum;

/**
 * One named result of a step: accepted, declined, waitlisted. Never a
 * boolean. Each outcome has its own effects and its own transition.
 */
final class Outcome
{
    private string $title = '';

    private bool $requiresReason = false;

    /** @var list<array{class: class-string<Effect>, args: ?EffectArgs}> */
    private array $effects = [];

    private ?Transition $transition = null;

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

    /** The person must give a reason. It is kept on the step completion and the audit line. */
    public function requiresReason(): self
    {
        $this->requiresReason = true;

        return $this;
    }

    /** @param  class-string<Effect>  $effect */
    public function effect(string $effect, ?EffectArgs $args = null): self
    {
        $this->effects[] = ['class' => $effect, 'args' => $args];

        return $this;
    }

    public function transition(Transition $transition): self
    {
        $this->transition = $transition;

        return $this;
    }

    public function titleText(): string
    {
        return $this->title;
    }

    public function needsReason(): bool
    {
        return $this->requiresReason;
    }

    /** @return list<array{class: class-string<Effect>, args: ?EffectArgs}> */
    public function effects(): array
    {
        return $this->effects;
    }

    public function transitionTo(): ?Transition
    {
        return $this->transition;
    }
}
