<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Sheet;

enum OutcomeId: string
{
    case Submitted = 'submitted';
    case Moderated = 'moderated';
    case Approved = 'approved';
    case Returned = 'returned';
    case Released = 'released';
}
