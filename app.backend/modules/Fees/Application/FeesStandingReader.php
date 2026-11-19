<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Modules\Fees\Contracts\FeesStanding;

/** The Fees contract for other modules, read from the ledger (D-098). */
final class FeesStandingReader implements FeesStanding
{
    public function __construct(private readonly Ledger $ledger) {}

    public function owedAndPaid(string $learnerId): array
    {
        $s = $this->ledger->standing($learnerId);

        return ['owed' => max(0, $s['charged'] - $s['reduced']), 'paid' => $s['paid']];
    }
}
