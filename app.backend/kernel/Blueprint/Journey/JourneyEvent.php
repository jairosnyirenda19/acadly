<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Events\DomainEvent;

/**
 * A domain event that a transition emits. The engine builds it from the step
 * that was performed and writes it to the outbox in the same transaction.
 */
interface JourneyEvent extends DomainEvent
{
    public static function fromJourney(JourneyContext $context, string $toState): static;
}
