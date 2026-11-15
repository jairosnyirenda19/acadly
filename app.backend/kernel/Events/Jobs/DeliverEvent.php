<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events\Jobs;

use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\EventRecord;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives one event to one consumer, at most once.
 *
 * The delivery record and the consumer's own writes share one transaction:
 * if the consumer fails, neither is kept and the job is retried; if it
 * succeeds, a second delivery of the same event finds the record and stops.
 */
final class DeliverEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(
        public readonly string $eventId,
        public readonly string $consumer,
    ) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900, 1800, 3600];
    }

    public function handle(EventCatalogue $catalogue, Container $container, Clock $clock): void
    {
        $row = DB::table('outbox_events')->where('id', $this->eventId)->first(['id', 'name', 'payload', 'occurred_at']);

        if ($row === null || ! is_string($row->name) || ! is_string($row->payload) || ! is_string($row->occurred_at)) {
            return;
        }

        $decoded = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $facts = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $facts[$key] = $value;
            }
        }

        $event = $catalogue->hydrate($row->name, $facts);
        $consumer = $catalogue->makeConsumer($this->consumer, $container);
        $record = new EventRecord($this->eventId, $row->name, CarbonImmutable::parse($row->occurred_at));

        DB::transaction(function () use ($consumer, $event, $record, $clock): void {
            $first = DB::table('consumed_events')->insertOrIgnore([
                'consumer' => $this->consumer,
                'event_id' => $this->eventId,
                'consumed_at' => $clock->now(),
            ]);

            if ($first === 0) {
                return;
            }

            $consumer->handle($event, $record);
        });
    }

    public function failed(?Throwable $e): void
    {
        DB::table('outbox_events')->where('id', $this->eventId)->update([
            'last_error' => mb_substr("Consumer {$this->consumer} failed after {$this->tries} attempts.", 0, 500),
        ]);

        Log::error('An event consumer failed.', [
            'event_id' => $this->eventId,
            'consumer' => $this->consumer,
            'error' => $e !== null ? $e::class : null,
        ]);
    }
}
