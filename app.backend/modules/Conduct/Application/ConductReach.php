<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A learner's conduct lives where the learner is placed. Someone who may
 * neither see conduct nor act on it there is told "not found".
 */
final class ConductReach
{
    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
    ) {}

    /** The learner's class, checked for the permission. */
    public function learner(User $who, string $learnerId, ConductPermission $permission): ScopeRef
    {
        $at = $this->at($learnerId);

        if ($at === null || (! $this->access->allows($who, ConductPermission::View, $at) && ! $this->access->allows($who, $permission, $at))) {
            throw new NotFoundHttpException;
        }

        $this->access->check($who, $permission, $at);

        return $at;
    }

    public function may(User $who, ConductPermission $permission, string $learnerId): bool
    {
        $at = $this->at($learnerId);

        return $at !== null && $this->access->allows($who, $permission, $at);
    }

    public function classOf(string $learnerId): ?string
    {
        return $this->directory->placeOf($learnerId)['class_id'] ?? null;
    }

    private function at(string $learnerId): ?ScopeRef
    {
        $class = $this->classOf($learnerId);

        return $class === null ? null : ScopeRef::of(ScopeType::ClassGroup, $class);
    }
}
