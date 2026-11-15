<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Access\Permission;

enum InstitutionPermission: string implements Permission
{
    case Manage = 'institution.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Manage the school';
    }

    public function help(): string
    {
        return 'The school details, logo, levels, campuses, and opening the school.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
