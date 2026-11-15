<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/** Commitment: the state an outcome moves the record to, and the events it tells. */
final class Transition
{
    /** @var list<class-string<JourneyEvent>> */
    private array $emits = [];

    private function __construct(public readonly JourneyState $to) {}

    public static function to(JourneyState $state): self
    {
        return new self($state);
    }

    /** @param  class-string<JourneyEvent>  ...$events */
    public function emits(string ...$events): self
    {
        $this->emits = [...$this->emits, ...array_values($events)];

        return $this;
    }

    /** @return list<class-string<JourneyEvent>> */
    public function events(): array
    {
        return $this->emits;
    }
}
