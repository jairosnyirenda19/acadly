<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\Permission;

/** The kernel's own notification permission: looking after delivery for the school. */
enum NotifyPermission: string implements Permission
{
    case Manage = 'notify.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Manage notifications';
    }

    public function help(): string
    {
        return 'Review deliveries and act when messages are not reaching people.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
