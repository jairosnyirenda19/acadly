<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Review;

enum OutcomeId: string
{
    case Submitted = 'submitted';
    case Checked = 'checked';
    case Returned = 'returned';
    case Shared = 'shared';
}
