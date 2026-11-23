<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Award;

enum OutcomeId: string
{
    case Approved = 'approved';
    case Confirmed = 'confirmed';
    case Returned = 'returned';
    case Conferred = 'conferred';
}
