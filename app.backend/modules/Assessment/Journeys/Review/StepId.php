<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Review;

enum StepId: string
{
    case Submit = 'submit';
    case Check = 'check';
    case Return = 'return';
    case Share = 'share';
}
