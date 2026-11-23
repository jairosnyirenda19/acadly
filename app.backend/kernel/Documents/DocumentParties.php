<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Identity\User;

/**
 * A document owner may also name the people a record belongs to (D-103): a
 * student and their own hand-in, the students of a course and its
 * resources. They hold no permission, so the owner says yes record by
 * record. Implemented by a DocumentOwner; the kernel asks only when the
 * permission is not held.
 */
interface DocumentParties
{
    /** May this person open the documents of this record? */
    public function mayRead(User $who, string $ownerId): bool;

    /** May this person add a document of this kind to this record? */
    public function mayStore(User $who, string $ownerId): bool;
}
