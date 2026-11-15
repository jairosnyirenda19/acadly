<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use BackedEnum;

/**
 * A group on the notification settings pages. The kernel declares Account
 * and System; each module declares the categories of its own notices.
 */
interface NoticeCategory extends BackedEnum
{
    public function label(): string;
}
