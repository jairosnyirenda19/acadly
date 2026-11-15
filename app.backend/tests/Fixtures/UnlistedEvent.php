<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Events\DomainEvent;

/** An event that no manifest declares. */
final readonly class UnlistedEvent implements DomainEvent
{
    public static function eventName(): string
    {
        return 'stray.happened';
    }

    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
