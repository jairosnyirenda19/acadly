<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Access\Permission;

enum WrongPrefixPermission: string implements Permission
{
    case Borrow = 'attendance.borrow';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Borrow';
    }

    public function help(): string
    {
        return 'A permission declared under the wrong module.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
