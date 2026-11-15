<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Console;

use Acadly\Kernel\Documents\Storage\StorageMeter;
use Illuminate\Console\Command;

/** Measures document storage against the licence and warns at 80% and 95%. Runs hourly. */
final class DocumentsStorageCommand extends Command
{
    protected $signature = 'documents:storage';

    protected $description = 'Measure document storage against the licence and warn when it is filling up';

    public function handle(StorageMeter $storage): int
    {
        $level = $storage->warnIfNeeded();
        $limit = $storage->limit();
        $used = number_format($storage->used() / 1024 ** 3, 2);

        $this->components->info($limit === null
            ? "Document storage used: {$used} GB. The licence sets no limit."
            : "Document storage used: {$used} GB of ".number_format($limit / 1024 ** 3, 0)." GB. Warning level: {$level}%.");

        return self::SUCCESS;
    }
}
