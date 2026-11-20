<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Fees\Contracts\FeesStanding;

/** The Fees contract for other modules, read from the ledger (D-098). */
final class FeesStandingReader implements FeesStanding
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FeeDocuments $documents,
        private readonly EnrolmentDirectory $directory,
    ) {}

    public function ownFees(string $learnerId): array
    {
        $s = $this->ledger->standing($learnerId);
        $payments = [];
        foreach ($this->ledger->statement($learnerId) as $line) {
            if ($line['paid'] !== null && $line['payment_id'] !== null) {
                $payments[] = ['number' => $line['reference'], 'received_on' => $line['date'], 'amount' => $line['paid'], 'cancelled' => false];
            }
        }

        return [
            'billed' => max(0, $s['charged'] - $s['reduced']), 'paid' => $s['paid'], 'left' => $s['left'], 'due_now' => $s['due_now'],
            'instalments' => array_map(fn (array $i): array => ['label' => $i['label'], 'charge' => $i['charge'], 'due_on' => $i['due_on'], 'amount' => $i['amount'],
                'covered' => $i['covered'], 'state' => $i['state']], $s['instalments']),
            'payments' => array_reverse($payments),
        ];
    }

    public function ownStatement(User $viewer, string $learnerId): array
    {
        return $this->documents->statementOf($viewer, $learnerId, $this->directory->names([$learnerId])[$learnerId] ?? '', null);
    }

    public function owedAndPaid(string $learnerId): array
    {
        $s = $this->ledger->standing($learnerId);

        return ['owed' => max(0, $s['charged'] - $s['reduced']), 'paid' => $s['paid']];
    }
}
