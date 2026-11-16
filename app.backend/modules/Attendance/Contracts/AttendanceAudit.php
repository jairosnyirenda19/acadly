<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum AttendanceAudit: string implements AuditAction
{
    case RegisterSubmitted = 'attendance.register_submitted';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return 'Submitted the register of a class.';
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
