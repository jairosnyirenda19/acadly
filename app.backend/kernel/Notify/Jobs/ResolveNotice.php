<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Jobs;

use Acadly\Kernel\Notify\NoticeDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Resolves one sent notice into deliveries. Dispatched after commit; safe to run again. */
final class ResolveNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly string $requestId) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900, 1800, 3600];
    }

    public function handle(NoticeDispatcher $dispatcher): void
    {
        $dispatcher->resolve($this->requestId);
    }
}
