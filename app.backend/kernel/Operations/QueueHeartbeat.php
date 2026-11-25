<?php

declare(strict_types=1);

namespace Acadly\Kernel\Operations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Sent by the scheduler every minute; a running queue worker records the beat (D-112). */
final class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->beat(Heartbeats::QUEUE);
    }
}
