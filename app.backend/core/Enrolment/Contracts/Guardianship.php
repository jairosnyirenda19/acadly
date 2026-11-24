<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * What a parent may see: the children whose link they confirmed and that is
 * active now (the `GuardianOf` rule, 03 §5.4). A module that shows a parent
 * anything about a learner asks this first, on every request.
 */
interface Guardianship
{
    /** The learner's link to this parent is active now. */
    public function sees(User $parent, string $learnerId): bool;

    /**
     * The child as the parent portal shows it, or null when the parent may not
     * see the learner.
     *
     * @return array{id: string, first_name: string, name: string, class_id: string|null, class: string}|null
     */
    public function child(User $parent, string $learnerId): ?array;

    /**
     * Every child this parent sees now (an active link), as `child()` shows each.
     *
     * @return list<array{id: string, first_name: string, name: string, class_id: string|null, class: string}>
     */
    public function children(User $parent): array;
}
