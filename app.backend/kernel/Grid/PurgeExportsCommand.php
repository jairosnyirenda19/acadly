<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Deletes the files of exports kept longer than a week. The record of each export stays. */
final class PurgeExportsCommand extends Command
{
    protected $signature = 'grids:purge-exports';

    protected $description = 'Delete export files older than a week.';

    public function handle(Clock $clock): int
    {
        $count = 0;

        foreach (DB::table('grid_exports')->where('status', 'ready')->where('expires_at', '<', $clock->now())->get(['id', 'path']) as $row) {
            $path = Row::maybeText($row, 'path');

            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            DB::table('grid_exports')->where('id', Row::text($row, 'id'))->update(['status' => 'expired', 'path' => null]);
            $count++;
        }

        $this->info("{$count} export file(s) deleted.");

        return self::SUCCESS;
    }
}
