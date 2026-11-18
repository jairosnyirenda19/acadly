<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum SupportCategory: string implements NoticeCategory
{
    case Support = 'support';

    public function label(): string
    {
        return 'Support';
    }
}
