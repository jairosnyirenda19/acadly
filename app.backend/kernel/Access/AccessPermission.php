<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/** The kernel's own permissions: managing access itself. */
enum AccessPermission: string implements Permission
{
    case AssignRoles = 'access.roles.assign';
    case ManageRoles = 'access.roles.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::AssignRoles => 'Give and end roles',
            self::ManageRoles => 'Create and edit roles',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::AssignRoles => 'Limited to roles and scopes held by the assigner.',
            self::ManageRoles => 'Custom roles only. System roles are locked.',
        };
    }

    public function isSight(): bool
    {
        return false;
    }
}
