<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Sheet;

enum StepId: string
{
    case Submit = 'submit';
    case Moderate = 'moderate';
    case Approve = 'approve';
    case Release = 'release';
}
