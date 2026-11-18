<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Journeys\SeriousIncident;

enum OutcomeId: string
{
    case TakenUp = 'taken_up';
    case Decided = 'decided';
    case Met = 'met';
    case Told = 'told';
    case Closed = 'closed';
    case Withdrawn = 'withdrawn';
}
