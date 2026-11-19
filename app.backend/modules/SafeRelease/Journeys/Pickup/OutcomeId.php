<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Journeys\Pickup;

enum OutcomeId: string
{
    case Verified = 'verified';
    case Released = 'released';
    case NotOnTheList = 'not_on_the_list';
    case Cancelled = 'cancelled';
}
