<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Console;

use Acadly\Kernel\Documents\Actions\PurgeTrash;
use Illuminate\Console\Command;

/** Empties the document trash of items older than the trash period. Runs daily. */
final class DocumentsPurgeCommand extends Command
{
    protected $signature = 'documents:purge';

    protected $description = 'Empty the document trash of items older than the trash period';

    public function handle(PurgeTrash $purge): int
    {
        $this->components->info('Documents purged: '.$purge->handle().'.');

        return self::SUCCESS;
    }
}
