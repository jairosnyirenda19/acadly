<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Journeys\Correction;

enum StepId: string
{
    case Request = 'request';
    case Decide = 'decide';
}
