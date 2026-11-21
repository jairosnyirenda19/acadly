<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

/** The notices of Lectures (D-102). */
enum LecturesCategory: string implements NoticeCategory
{
    case Lectures = 'lectures';

    public function label(): string
    {
        return 'Lectures';
    }
}
