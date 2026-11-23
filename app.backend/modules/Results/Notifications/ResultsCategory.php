<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

/** The notices of Results (D-105). */
enum ResultsCategory: string implements NoticeCategory
{
    case Results = 'results';

    public function label(): string
    {
        return 'Results';
    }
}
