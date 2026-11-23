<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Award;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case WorkedOut = 'worked_out';
    case Approved = 'approved';
    case Confirmed = 'confirmed';
    case Conferred = 'conferred';

    public function label(): string
    {
        return match ($this) {
            self::WorkedOut => 'Worked out',
            self::Approved => 'Approved by the board',
            self::Confirmed => 'Confirmed by the academic board',
            self::Conferred => 'Conferred',
        };
    }
}
