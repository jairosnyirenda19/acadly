<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

enum KernelCategory: string implements NoticeCategory
{
    case Account = 'account';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account',
            self::System => 'System',
        };
    }
}
