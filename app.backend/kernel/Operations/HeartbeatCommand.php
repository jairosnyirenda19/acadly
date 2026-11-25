<?php

declare(strict_types=1);

namespace Acadly\Kernel\Operations;

use Illuminate\Console\Command;

/** Run by the scheduler every minute: records its own beat and asks the queue worker for one (D-112). */
final class HeartbeatCommand extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Record that the scheduler is running, and ask the queue worker to do the same.';

    public function handle(Heartbeats $heartbeats): int
    {
        $heartbeats->beat(Heartbeats::SCHEDULER);
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
