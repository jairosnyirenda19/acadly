<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\Progression;

enum OutcomeId: string
{
    case Promoted = 'promoted';
    case Repeated = 'repeated';
    case Progressed = 'progressed';
    case Completed = 'completed';
    case Left = 'left';
    case Accepted = 'accepted';
    case TurnedBack = 'turned_back';
    case Applied = 'applied';
    case Cancelled = 'cancelled';
}
