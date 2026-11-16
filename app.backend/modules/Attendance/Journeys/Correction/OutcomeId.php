<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Journeys\Correction;

enum OutcomeId: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Declined = 'declined';
}
