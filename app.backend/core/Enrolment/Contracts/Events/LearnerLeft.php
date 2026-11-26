<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts\Events;

use Acadly\Kernel\Events\DomainEvent;

/**
 * A learner left the school during the year (D-114). Fees reads it to ask
 * for the fees not yet due to be waived, when the office asked for that.
 */
final readonly class LearnerLeft implements DomainEvent
{
    public function __construct(
        public string $learnerId,
        public string $lastDay,
        public bool $waiveAsked,
        public string $recordedBy,
    ) {}

    public static function eventName(): string
    {
        return 'enrolment.learner_left';
    }

    public function toPayload(): array
    {
        return ['learner_id' => $this->learnerId, 'last_day' => $this->lastDay, 'waive_asked' => $this->waiveAsked, 'recorded_by' => $this->recordedBy];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            is_string($payload['learner_id'] ?? null) ? $payload['learner_id'] : '',
            is_string($payload['last_day'] ?? null) ? $payload['last_day'] : '',
            ($payload['waive_asked'] ?? false) === true,
            is_string($payload['recorded_by'] ?? null) ? $payload['recorded_by'] : '',
        );
    }
}
