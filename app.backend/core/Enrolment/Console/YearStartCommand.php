<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Console;

use Acadly\Core\Enrolment\Application\Actions\ProgressionActions;
use Illuminate\Console\Command;

/** Every morning: the year-end outcomes whose school year has started take effect (D-088). */
final class YearStartCommand extends Command
{
    protected $signature = 'learners:year-start';

    protected $description = 'Applies the year-end outcomes whose next school year has started.';

    public function handle(ProgressionActions $actions): int
    {
        $applied = $actions->applyDue();
        $this->info($applied === 0 ? 'No outcome was due.' : "{$applied} outcomes took effect.");

        return self::SUCCESS;
    }
}
