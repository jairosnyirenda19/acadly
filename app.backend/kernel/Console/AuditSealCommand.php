<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Audit\Seals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Verifies the audit trail, then seals its head. Runs nightly. */
final class AuditSealCommand extends Command
{
    protected $signature = 'audit:seal';

    protected $description = 'Verify the audit trail and seal its head';

    public function handle(Seals $seals): int
    {
        $result = $seals->seal();

        if ($result['sealed']) {
            $this->components->info("The audit trail is sealed at line {$result['lines']}.");

            return self::SUCCESS;
        }

        // Until the notification catalogue exists, a failed verification is raised in the log.
        Log::critical('The audit trail does not verify.', ['problems' => $result['problems']]);

        foreach ($result['problems'] as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
