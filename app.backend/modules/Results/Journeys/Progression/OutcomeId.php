<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Progression;

enum OutcomeId: string
{
    case Decided = 'decided';
    case Reopened = 'reopened';
    case Applied = 'applied';
}
