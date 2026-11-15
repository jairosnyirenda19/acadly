<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Access\Permission;

/** The kernel's permission for switching modules on and off. */
enum ModulePermission: string implements Permission
{
    case Manage = 'modules.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Switch modules on and off';
    }

    public function help(): string
    {
        return 'Only modules that the licence includes. Core functions are always on.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
