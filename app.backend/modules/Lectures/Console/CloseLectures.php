<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Console;

use Acadly\Modules\Lectures\Application\Actions\SessionActions;
use Illuminate\Console\Command;

/** Closes lecture attendance once each lecture has ended: students with no mark are Absent (D-102). */
final class CloseLectures extends Command
{
    protected $signature = 'lectures:close';

    protected $description = 'Close attendance at lectures that have ended.';

    public function handle(SessionActions $sessions): int
    {
        $this->info($sessions->settle().' lectures closed.');

        return self::SUCCESS;
    }
}
