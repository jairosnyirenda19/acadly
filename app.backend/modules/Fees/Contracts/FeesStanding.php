<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Contracts;

/**
 * What another module may know about a learner's fees: how much is owed
 * in all (charged less reductions) and how much is paid, in minor units.
 * Programmes reads it for the fees gate of registration (D-098).
 */
interface FeesStanding
{
    /** @return array{owed: int, paid: int} */
    public function owedAndPaid(string $learnerId): array;
}
