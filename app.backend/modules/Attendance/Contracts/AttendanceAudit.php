<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum AttendanceAudit: string implements AuditAction
{
    case RegisterSubmitted = 'attendance.register_submitted';
    case AbsenceFollowedUp = 'attendance.absence_followed_up';
    case LessonRegisterSubmitted = 'attendance.lesson_register_submitted';
    case AwaySet = 'attendance.away_set';
    case CoverAsked = 'attendance.cover_asked';
    case AbsenceReasonGiven = 'attendance.absence_reason_given';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::RegisterSubmitted => 'Submitted the register of a class.',
            self::AbsenceFollowedUp => 'Told the next contact of an absence, or recorded a call to the family.',
            self::LessonRegisterSubmitted => 'Submitted the register of a lesson.',
            self::AwaySet => 'Marked a member of staff away for a day, or back.',
            self::CoverAsked => 'Asked a member of staff to cover a lesson.',
            self::AbsenceReasonGiven => 'Gave the reason for an absence in the parent portal.',
        };
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
