<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

/** The notices of Classroom (D-104). */
enum ClassroomCategory: string implements NoticeCategory
{
    case Classroom = 'classroom';

    public function label(): string
    {
        return 'Classroom';
    }
}
