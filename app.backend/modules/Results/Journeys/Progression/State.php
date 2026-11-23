<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Progression;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case WorkedOut = 'worked_out';
    case Decided = 'decided';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::WorkedOut => 'Worked out',
            self::Decided => 'Decided by the board',
            self::Applied => 'Applied',
        };
    }
}
