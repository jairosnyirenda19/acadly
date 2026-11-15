<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/** What a gate found: the step may go ahead, or it is blocked with a reason and a remedy. */
final readonly class GateResult
{
    private function __construct(
        public bool $passed,
        public ?string $reason,
        public ?string $remedy,
    ) {}

    public static function ok(): self
    {
        return new self(true, null, null);
    }

    /**
     * @param  string  $reason  one plain sentence: what is not true yet
     * @param  string  $remedy  one plain sentence: what makes it true
     */
    public static function blocked(string $reason, string $remedy): self
    {
        return new self(false, $reason, $remedy);
    }
}
