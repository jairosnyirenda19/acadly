<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\Permission;

/**
 * A document kind that more than one kind of work may read: a marked paper
 * is read by those who see marks, those who enter them and those who lock
 * them. Each permission is still checked at the owner record's scope.
 */
interface AlsoReadWith
{
    /** @return list<Permission> */
    public function alsoReadWith(): array;
}
