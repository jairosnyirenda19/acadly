<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;

/**
 * Who may see a class's register: someone who takes it, sees every
 * register, or approves corrections there. Anyone else is told "not found".
 */
final class Reach
{
    public function __construct(private readonly Access $access) {}

    public function sees(User $who, string $classId): bool
    {
        $at = ScopeRef::of(ScopeType::ClassGroup, $classId);

        return $this->access->allows($who, AttendancePermission::Capture, $at)
            || $this->access->allows($who, AttendancePermission::AllClasses, $at)
            || $this->access->allows($who, AttendancePermission::Correct, $at);
    }

    public function may(User $who, AttendancePermission $permission, string $classId): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId));
    }
}
