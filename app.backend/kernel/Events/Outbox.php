<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

use Acadly\Kernel\Events\Jobs\PublishOutbox;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes domain events to the outbox, inside the transaction of the change
 * they report (D-013). A rolled-back change leaves no event; a committed
 * change always has its events. Publishing starts after commit.
 */
final class Outbox
{
    public function __construct(
        private readonly EventCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    /** Returns the event's id. */
    public function record(DomainEvent $event): string
    {
        $name = $event::eventName();

        if (! $this->catalogue->has($name)) {
            throw new LogicException("[{$name}] is not an event in this build.");
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException("[{$name}] must be written in the transaction of the change it reports.");
        }

        $id = Ids::next();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'name' => $name,
            'payload' => json_encode($event->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $this->clock->now(),
        ]);

        // Discarded with the transaction if it rolls back. The job is unique, so many events make one publish.
        DB::afterCommit(fn () => PublishOutbox::dispatch());

        return $id;
    }
}
