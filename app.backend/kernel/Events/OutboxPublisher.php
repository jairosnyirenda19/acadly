<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

use Acadly\Kernel\Events\Jobs\DeliverEvent;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Publishes committed events: one delivery job per event and consumer, then
 * the event is marked published. Rows are claimed with SKIP LOCKED, so two
 * workers never publish the same event.
 */
final class OutboxPublisher
{
    public function __construct(
        private readonly EventCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    /** Publishes up to `$limit` events. Returns how many were published. */
    public function publish(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit): int {
            $rows = DB::table('outbox_events')
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get(['id', 'name']);

            foreach ($rows as $row) {
                $id = is_string($row->id) ? $row->id : '';
                $name = is_string($row->name) ? $row->name : '';
                $known = $this->catalogue->has($name);

                foreach ($known ? $this->catalogue->consumersOf($name) : [] as $consumer) {
                    DeliverEvent::dispatch($id, $consumer)->afterCommit();
                }

                DB::table('outbox_events')->where('id', $id)->update([
                    'published_at' => $this->clock->now(),
                    'last_error' => $known ? null : 'The event is not in this build; nothing consumes it.',
                ]);
            }

            return $rows->count();
        });
    }
}
