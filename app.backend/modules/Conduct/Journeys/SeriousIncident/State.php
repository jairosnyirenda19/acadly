<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Journeys\SeriousIncident;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Reported = 'reported';
    case LookedInto = 'looked_into';
    case Decided = 'decided';
    case FamilyMet = 'family_met';
    case Closed = 'closed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::LookedInto => 'Being looked into',
            self::Decided => 'Decided',
            self::FamilyMet => 'Family met',
            self::Closed => 'Closed',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
