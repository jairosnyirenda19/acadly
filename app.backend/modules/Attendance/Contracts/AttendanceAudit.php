<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum AttendanceAudit: string implements AuditAction
{
    case RegisterSubmitted = 'attendance.register_submitted';
    case AbsenceFollowedUp = 'attendance.absence_followed_up';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::RegisterSubmitted => 'Submitted the register of a class.',
            self::AbsenceFollowedUp => 'Told the next contact of an absence, or recorded a call to the family.',
        };
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
