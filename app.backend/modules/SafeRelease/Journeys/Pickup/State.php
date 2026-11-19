<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Journeys\Pickup;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Announced = 'announced';
    case Verified = 'verified';
    case Released = 'released';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Announced => 'At the gate',
            self::Verified => 'Checked',
            self::Released => 'Collected',
            self::Disputed => 'Not released',
            self::Cancelled => 'Cancelled',
        };
    }
}
