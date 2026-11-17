<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Notifications;

use Acadly\Kernel\Notify\NoticeCategory;

enum FamilyCategory: string implements NoticeCategory
{
    case Families = 'families';

    public function label(): string
    {
        return 'Families';
    }
}
