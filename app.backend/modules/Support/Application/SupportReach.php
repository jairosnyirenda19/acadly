<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Domain\SupportPlan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Support lives where the learner is placed. A confidential plan is seen only
 * by its lead and by those who may see confidential plans there. Anyone
 * else is told "not found".
 */
final class SupportReach
{
    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
    ) {}

    public function learner(User $who, string $learnerId, SupportPermission $permission): ScopeRef
    {
        $at = $this->at($learnerId);

        if ($at === null || (! $this->access->allows($who, SupportPermission::View, $at) && ! $this->access->allows($who, $permission, $at))) {
            throw new NotFoundHttpException;
        }

        $this->access->check($who, $permission, $at);

        return $at;
    }

    public function may(User $who, SupportPermission $permission, string $learnerId): bool
    {
        $at = $this->at($learnerId);

        return $at !== null && $this->access->allows($who, $permission, $at);
    }

    /** Whether the person may see this plan at all. */
    public function seesPlan(User $who, SupportPlan $plan): bool
    {
        $at = $plan->journeyScope();

        if (! $this->access->allows($who, SupportPermission::View, $at) && ! $this->access->allows($who, SupportPermission::Lead, $at)) {
            return false;
        }

        return ! $plan->confidential || $plan->lead_by === $who->id || $this->access->allows($who, SupportPermission::Confidential, $at);
    }

    public function plan(User $who, string $id): SupportPlan
    {
        $plan = SupportPlan::query()->find($id);

        return $plan instanceof SupportPlan && $this->seesPlan($who, $plan) ? $plan : throw new NotFoundHttpException;
    }

    /** Whether the person holds the permission at a class: for lists that already know each learner's class. */
    public function mayAtClass(User $who, SupportPermission $permission, string $classId): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId));
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
