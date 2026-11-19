<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum SafeReleaseCategory: string implements NoticeCategory
{
    case SafeRelease = 'saferelease';

    public function label(): string
    {
        return 'Pick-up';
    }
}
