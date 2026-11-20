<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * What another module may know about a learner's fees: how much is owed
 * in all (charged less reductions) and how much is paid, in minor units.
 * Programmes reads it for the fees gate of registration (D-098).
 */
interface FeesStanding
{
    /** @return array{owed: int, paid: int} */
    public function owedAndPaid(string $learnerId): array;

    /**
     * A student's own fees for the student portal (D-101): what is billed, paid and left, each
     * instalment with its state, and the payments with their receipt numbers. The caller has
     * proved the learner is the person asking.
     *
     * @return array{billed: int, paid: int, left: int, due_now: int, instalments: list<array{label: string, charge: string, due_on: string, amount: int, covered: int, state: string}>, payments: list<array{number: string, received_on: string, amount: int, cancelled: bool}>}
     */
    public function ownFees(string $learnerId): array;

    /**
     * The student's own fee statement as a PDF with its check code. The caller has proved
     * the learner is the person asking.
     *
     * @return array{name: string, pdf: string}
     */
    public function ownStatement(User $viewer, string $learnerId): array;
}
