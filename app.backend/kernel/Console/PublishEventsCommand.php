<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Events\OutboxPublisher;
use Illuminate\Console\Command;

/**
 * Publishes waiting events. Events are normally published right after their
 * commit; this runs every minute so that nothing waits if a worker was down.
 */
final class PublishEventsCommand extends Command
{
    protected $signature = 'events:publish';

    protected $description = 'Publish the domain events waiting in the outbox';

    public function handle(OutboxPublisher $publisher): int
    {
        $total = 0;

        while (($published = $publisher->publish()) > 0) {
            $total += $published;
        }

        $this->components->info("Published {$total} event(s).");

        return self::SUCCESS;
    }
}
