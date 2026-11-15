<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

use Acadly\Kernel\Access\Permission;

enum AcademicsPermission: string implements Permission
{
    case Manage = 'academics.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Manage the academic calendar and classes';
    }

    public function help(): string
    {
        return 'Academic years, terms, and the classes of each level at each campus.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
