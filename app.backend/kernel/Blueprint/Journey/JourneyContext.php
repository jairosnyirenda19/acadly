<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Identity\User;

/**
 * What a gate or an effect may know about the step in hand: the record, who
 * acts, the step and outcome, what the person submitted, and what earlier
 * effects of the same outcome produced.
 */
final readonly class JourneyContext
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<class-string<Effect>, string>  $produced
     */
    public function __construct(
        public JourneySubject $subject,
        public ?User $actor,
        public BlueprintRef $ref,
        public string $step,
        public ?string $outcome,
        public string $fromState,
        public array $input = [],
        public array $produced = [],
    ) {}

    /** A text value the person submitted, trimmed, or null. */
    public function text(string $key): ?string
    {
        $value = $this->input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The id produced by an earlier effect of this outcome, or null. */
    public function producedBy(string $effect): ?string
    {
        return $this->produced[$effect] ?? null;
    }

    /** @param  class-string<Effect>  $effect */
    public function withProduced(string $effect, string $id): self
    {
        return new self($this->subject, $this->actor, $this->ref, $this->step, $this->outcome, $this->fromState,
            $this->input, [...$this->produced, $effect => $id]);
    }

    public function withOutcome(string $outcome): self
    {
        return new self($this->subject, $this->actor, $this->ref, $this->step, $outcome, $this->fromState,
            $this->input, $this->produced);
    }
}
