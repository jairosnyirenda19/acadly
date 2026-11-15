<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Audit\Seals;
use Illuminate\Console\Command;

/** Walks the audit trail and checks it against its seals. Changes nothing. */
final class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit trail against its hash chain and seals';

    public function handle(Seals $seals): int
    {
        $result = $seals->verify();

        if ($result['problems'] === []) {
            $this->components->info("The audit trail verifies: {$result['lines']} lines.");

            return self::SUCCESS;
        }

        foreach ($result['problems'] as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
