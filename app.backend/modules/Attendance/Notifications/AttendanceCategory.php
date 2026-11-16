<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum AttendanceCategory: string implements NoticeCategory
{
    case Attendance = 'attendance';

    public function label(): string
    {
        return 'Attendance';
    }
}
