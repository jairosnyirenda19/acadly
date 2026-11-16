<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Journeys\Correction;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Drafted = 'drafted';
    case Requested = 'requested';
    case Approved = 'approved';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Drafted => 'Being written',
            self::Requested => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
        };
    }
}
