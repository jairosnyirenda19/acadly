<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

use Carbon\CarbonImmutable;

/** Where and when an event was recorded, for its consumers. */
final readonly class EventRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public CarbonImmutable $occurredAt,
    ) {}
}
