<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;

/**
 * Who may see a class's register: someone who takes it, sees every
 * register, or approves corrections there. Anyone else is told "not found".
 *
 * Who takes the morning register is a fact as well as a permission: the
 * class's class teacher, set on the Teaching screen. "Take the register"
 * says someone may take registers where it is held; the class teacher says
 * which. Someone who arranges cover there may step in for an absent class
 * teacher, and a class with no class teacher yet is taken by anyone who may.
 */
final class Reach
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
    ) {}

    public function sees(User $who, string $classId): bool
    {
        $at = ScopeRef::of(ScopeType::ClassGroup, $classId);

        return $this->access->allows($who, AttendancePermission::Capture, $at)
            || $this->access->allows($who, AttendancePermission::AllClasses, $at)
            || $this->access->allows($who, AttendancePermission::Correct, $at);
    }

    /** Whether the class belongs on the viewer's list of morning registers: theirs to take, or theirs to watch. */
    public function lists(User $who, string $classId): bool
    {
        return $this->takesMorning($who, $classId)
            || $this->may($who, AttendancePermission::AllClasses, $classId)
            || $this->may($who, AttendancePermission::Correct, $classId);
    }

    /** Whether the viewer takes this class's morning register. */
    public function takesMorning(User $who, string $classId): bool
    {
        if (! $this->may($who, AttendancePermission::Capture, $classId)) {
            return false;
        }

        $teacher = $this->calendar->classTeacherId($classId);

        return $teacher === null || $teacher === $who->id || $this->may($who, AttendancePermission::Cover, $classId);
    }

    public function may(User $who, AttendancePermission $permission, string $classId): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId));
    }
}
