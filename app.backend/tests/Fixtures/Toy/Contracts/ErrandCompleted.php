<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Contracts;

use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Blueprint\Journey\JourneyEvent;

final readonly class ErrandCompleted implements JourneyEvent
{
    public function __construct(
        public string $errandId,
        public string $state,
    ) {}

    public static function eventName(): string
    {
        return 'toy.errand_completed';
    }

    public static function fromJourney(JourneyContext $context, string $toState): static
    {
        return new self($context->subject->auditId(), $toState);
    }

    public function toPayload(): array
    {
        return ['errand_id' => $this->errandId, 'state' => $this->state];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['errand_id'] ?? '';
        $state = $payload['state'] ?? '';

        return new self(is_string($id) ? $id : '', is_string($state) ? $state : '');
    }
}
