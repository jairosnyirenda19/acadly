<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What safe pick-up writes to the audit trail. Releases and refusals are journey steps: the engine writes their lines. */
enum SafeReleaseAudit: string implements AuditAction
{
    case CollectorAdded = 'saferelease.collector_added';
    case CollectorEnded = 'saferelease.collector_ended';
    case Barred = 'saferelease.barred';
    case BarEnded = 'saferelease.bar_ended';
    case Arrived = 'saferelease.arrived';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::CollectorAdded => 'Added someone who may collect a child.',
            self::CollectorEnded => 'Ended someone\'s right to collect a child.',
            self::Barred => 'Recorded a family member who must not collect a child.',
            self::BarEnded => 'Ended a record that a family member must not collect a child.',
            self::Arrived => 'Marked someone at the gate to collect a child.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::Barred || $this === self::BarEnded;
    }
}
