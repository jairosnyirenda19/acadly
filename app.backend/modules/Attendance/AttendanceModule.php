<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Notifications\LearnerAbsent;
use Acadly\Modules\Attendance\Notifications\LearnerAbsentNextContact;
use Acadly\Modules\Attendance\Notifications\LessonMissed;

/**
 * Registers, the marks in them, and corrections (the approved Attendance
 * screens, first release): the morning register of each class, taken once
 * a school day, and corrections approved by someone else.
 */
final class AttendanceModule implements Module
{
    public function key(): string
    {
        return 'attendance';
    }

    public function name(): string
    {
        return 'Attendance';
    }

    public function summary(): string
    {
        return 'Morning and lesson registers, cover, corrections and absence notices to contacts.';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class, EnrolmentCore::class];
    }

    public function permissions(): array
    {
        return AttendancePermission::cases();
    }

    public function auditActions(): array
    {
        return AttendanceAudit::cases();
    }

    public function events(): array
    {
        return [];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return ['attendance.correction'];
    }

    public function topics(): array
    {
        return [LearnerAbsent::class, LearnerAbsentNextContact::class, LessonMissed::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
