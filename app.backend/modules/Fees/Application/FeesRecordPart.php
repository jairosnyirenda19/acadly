<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\Money;

/** The Fees part of the learner record: what is due now and what is left, for those who may see fees. */
final class FeesRecordPart implements LearnerRecordPart
{
    public function __construct(
        private readonly FeesReach $reach,
        private readonly Ledger $ledger,
    ) {}

    public function key(): string
    {
        return 'fees';
    }

    public function title(): string
    {
        return 'Fees';
    }

    public function band(User $viewer, string $learnerId): array
    {
        if (! $this->sees($viewer, $learnerId)) {
            return [];
        }

        $s = $this->ledger->standing($learnerId);

        if ($s['charged'] === 0) {
            return [];
        }

        return [
            ['label' => 'Fees due now', 'value' => Money::text($s['due_now']), 'note' => $s['overdue_since'] !== null ? 'Overdue since '.date('j M', (int) strtotime($s['overdue_since'])).'.' : 'Nothing overdue.',
                'tone' => $s['overdue_since'] !== null ? 'attention' : 'plain'],
        ];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return $this->sees($viewer, $learnerId) && $this->ledger->standing($learnerId)['overdue_since'] !== null ? 1 : 0;
    }

    private function sees(User $viewer, string $learnerId): bool
    {
        return $this->reach->may($viewer, FeesPermission::View, $learnerId) || $this->reach->may($viewer, FeesPermission::Collect, $learnerId);
    }
}
