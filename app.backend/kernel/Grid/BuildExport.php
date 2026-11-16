<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row as SheetRow;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Builds an export a person asked for: every row that matched when they
 * asked, in the columns they saw, as a spreadsheet. Runs on the queue; a
 * second run of the same export does nothing. The person is told in Notices.
 */
final class BuildExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $exportId) {}

    public function handle(Grids $grids, Notifier $notifier, Clock $clock): void
    {
        $claimed = DB::table('grid_exports')->where('id', $this->exportId)->where('status', 'queued')->update(['status' => 'building']);

        if ($claimed !== 1) {
            return;
        }

        $row = DB::table('grid_exports')->where('id', $this->exportId)->first();
        $user = $row !== null ? User::query()->find(Row::text($row, 'user_id')) : null;

        if ($row === null || ! $user instanceof User) {
            return;
        }

        $path = 'exports/'.$this->exportId.'.xlsx';
        $local = tempnam(sys_get_temp_dir(), 'export');

        try {
            $grid = $grids->grid(Row::text($row, 'grid'));
            $state = json_decode(Row::text($row, 'state'), true);
            $keys = json_decode(Row::text($row, 'columns'), true);
            $only = Row::maybeText($row, 'only') !== null ? json_decode(Row::text($row, 'only'), true) : null;
            $columns = array_values(array_filter($grid->columns(), fn (GridColumn $c): bool => is_array($keys) && in_array($c->key, $keys, true)));
            $writer = new Writer;
            $writer->openToFile((string) $local);
            $writer->addRow(SheetRow::fromValues(array_map(fn (GridColumn $c): string => $c->label, $columns)));
            $count = 0;

            $grids->each($user, $grid, is_array($state) ? $state : [], is_array($only) ? array_values(array_filter($only, is_string(...))) : null,
                function (array $rows) use ($writer, $grid, $columns, &$count): void {
                    foreach ($rows as $r) {
                        $writer->addRow(SheetRow::fromValues(array_map(fn (GridColumn $c): string|int|float|null => $grid->plain($r, $c->key), $columns)));
                        $count++;
                    }
                });

            $writer->close();
            Storage::disk('local')->put($path, (string) file_get_contents((string) $local));
            DB::table('grid_exports')->where('id', $this->exportId)->update(['status' => 'ready', 'rows' => $count, 'path' => $path,
                'finished_at' => $clock->now(), 'expires_at' => $clock->now()->addDays(GridActions::KEPT_DAYS)]);
            $notifier->send(new ExportReady($this->exportId, $grid->title(), $count), null, [$user]);
        } catch (Throwable $e) {
            DB::table('grid_exports')->where('id', $this->exportId)->update(['status' => 'failed', 'finished_at' => $clock->now()]);

            throw $e;
        } finally {
            if (is_string($local) && is_file($local)) {
                @unlink($local);
            }
        }
    }
}
