<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A learner's fees live where the learner is placed. Someone who may neither
 * see fees nor act on them there is told "not found", so a refusal never
 * confirms that a learner exists.
 */
final class FeesReach
{
    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /** The learner's class, checked for the permission: or not found, or refused. */
    public function learner(User $who, string $learnerId, FeesPermission $permission): ScopeRef
    {
        $place = $this->directory->placeOf($learnerId);
        $at = $place === null ? null : ScopeRef::of(ScopeType::ClassGroup, $place['class_id']);

        if ($at === null || (! $this->access->allows($who, FeesPermission::View, $at) && ! $this->access->allows($who, $permission, $at))) {
            throw new NotFoundHttpException;
        }

        $this->access->check($who, $permission, $at);

        return $at;
    }

    /** @return array{class_id: string, division_id: string, campus_id: string, year_id: string, open: bool, since: string}|null */
    public function place(string $learnerId): ?array
    {
        return $this->directory->placeOf($learnerId);
    }

    public function may(User $who, FeesPermission $permission, string $learnerId): bool
    {
        $place = $this->directory->placeOf($learnerId);

        return $place !== null && $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $place['class_id']));
    }

    /** Whether a day is closed: no payment may be recorded on it. */
    public function dayClosed(string $day): bool
    {
        return DB::table('fee_days')->where('day', $day)->exists();
    }

    public function today(): string
    {
        return $this->clock->today();
    }
}
