<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\Progression;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Proposed = 'proposed';
    case Decided = 'decided';
    case AwaitingIntake = 'awaiting_intake';
    case Effective = 'effective';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::Decided => 'Decided',
            self::AwaitingIntake => 'Awaiting intake',
            self::Effective => 'In effect',
            self::Cancelled => 'Cancelled',
        };
    }
}
