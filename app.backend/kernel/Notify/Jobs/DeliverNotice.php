<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Jobs;

use Acadly\Kernel\Notify\NoticeDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one delivery. A row that is no longer queued is left alone, so a second run sends nothing. */
final class DeliverNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $deliveryId) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(NoticeDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryId);
    }

    public function failed(?Throwable $e): void
    {
        app(NoticeDispatcher::class)->failed($this->deliveryId, 'provider_error');
    }
}
