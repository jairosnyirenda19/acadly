<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum AssessmentCategory: string implements NoticeCategory
{
    case Assessment = 'assessment';

    public function label(): string
    {
        return 'Assessment';
    }
}
