<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum ConductCategory: string implements NoticeCategory
{
    case Conduct = 'conduct';

    public function label(): string
    {
        return 'Conduct';
    }
}
