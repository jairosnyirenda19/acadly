<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A learner as one person may reach them: with the permission at the class
 * the learner is in. Anyone who may not see the learner is told "not
 * found", so a refusal never confirms that a learner exists.
 */
final class Reach
{
    public function __construct(
        private readonly Access $access,
        private readonly LearnerPlaces $places,
    ) {}

    /** @return array{learner: Learner, at: ScopeRef} */
    public function learner(User $by, string $id, EnrolmentPermission $permission): array
    {
        $at = $this->places->scopeOf($id);

        if ($at === null) {
            throw (new ModelNotFoundException)->setModel(Learner::class);
        }

        $learner = Learner::query()->find($id);

        if (! $learner instanceof Learner || ! $this->access->allows($by, EnrolmentPermission::View, $at)) {
            throw (new ModelNotFoundException)->setModel(Learner::class);
        }

        $this->access->check($by, $permission, $at);

        return ['learner' => $learner, 'at' => $at];
    }
}
