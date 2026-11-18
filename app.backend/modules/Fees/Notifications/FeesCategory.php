<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum FeesCategory: string implements NoticeCategory
{
    case Fees = 'fees';

    public function label(): string
    {
        return 'Fees';
    }
}
