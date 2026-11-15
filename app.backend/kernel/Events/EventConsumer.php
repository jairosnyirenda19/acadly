<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

/**
 * Reacts to a domain event. Each consumer sees each event at most once:
 * the delivery records (consumer, event) in the same transaction as the
 * consumer's own writes. A consumer that throws is retried later.
 */
interface EventConsumer
{
    /** A stable name for the delivery record, such as `notify.access_changes`. Never changes once released. */
    public static function consumerKey(): string;

    public function handle(DomainEvent $event, EventRecord $record): void;
}
