<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Engine;

/**
 * One step a record could take from its current state, for one person:
 * whether they may take it, and if a gate is closed, why and what would open
 * it. The screens render this; they never compare states themselves.
 */
final readonly class StepAvailability
{
    /** @param  list<array{outcome: string, title: string, requires_reason: bool}>  $outcomes */
    public function __construct(
        public string $step,
        public string $title,
        public array $outcomes,
        public bool $permitted,
        public ?string $blockedReason,
        public ?string $remedy,
    ) {}

    /** @return array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: ?string, remedy: ?string} */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'title' => $this->title,
            'outcomes' => $this->outcomes,
            'permitted' => $this->permitted,
            'blocked_reason' => $this->blockedReason,
            'remedy' => $this->remedy,
        ];
    }
}
