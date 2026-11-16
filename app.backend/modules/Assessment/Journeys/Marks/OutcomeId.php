<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Marks;

enum OutcomeId: string
{
    case Submitted = 'submitted';
    case Locked = 'locked';
    case Returned = 'returned';
}
