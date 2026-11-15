<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events\Jobs;

use Acadly\Kernel\Events\OutboxPublisher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Publishes everything waiting in the outbox. Dispatched after a commit that wrote events. */
final class PublishOutbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function handle(OutboxPublisher $publisher): void
    {
        while ($publisher->publish() > 0) {
            // Keep going until the outbox is empty.
        }
    }
}
