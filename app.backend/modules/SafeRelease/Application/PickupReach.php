<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pick-up lives where the child is placed. Anyone who holds a pick-up
 * permission at the class sees it; anyone else is told "not found".
 *
 * @phpstan-type ClassRow array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}
 */
final class PickupReach
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $directory,
    ) {}

    public function sees(User $who, string $classId): bool
    {
        foreach (SafeReleasePermission::cases() as $permission) {
            if ($this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId))) {
                return true;
            }
        }

        return false;
    }

    public function may(User $who, SafeReleasePermission $permission, string $classId): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId));
    }

    /**
     * The classes of this year the viewer sees pick-up in.
     *
     * @return list<ClassRow>
     */
    public function rooms(User $who): array
    {
        $year = $this->calendar->currentYear();

        return $year === null ? [] : array_values(array_filter($this->calendar->classesInYear($year['id']), fn (array $c): bool => $this->sees($who, $c['id'])));
    }

    /**
     * A class the viewer sees, or "not found".
     *
     * @return ClassRow
     */
    public function room(User $who, string $classId): array
    {
        $class = $this->calendar->classInForce($classId);

        return $class !== null && $this->sees($who, $classId) ? $class : throw new NotFoundHttpException;
    }

    /** The class of a placed child the viewer sees, or "not found"; with a permission, it is also checked. */
    public function learner(User $who, string $learnerId, ?SafeReleasePermission $permission = null): ScopeRef
    {
        $place = $this->directory->placeOf($learnerId);

        if ($place === null || ! $place['open'] || ! $this->sees($who, $place['class_id'])) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $place['class_id']);

        if ($permission !== null) {
            $this->access->check($who, $permission, $at);
        }

        return $at;
    }
}
