<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

/**
 * A step a domain adds to `acadly:sync`: seed data its code starts from.
 * Tagged `SyncStep::TAG`, run after the kernel's own steps, in the order the
 * domains are registered. A step is idempotent: it adds what is missing and
 * never changes what the school has edited.
 */
interface SyncStep
{
    public const string TAG = 'acadly.sync-steps';

    /** Brings the seed into line, and says in one sentence what it did. */
    public function sync(): string;
}
