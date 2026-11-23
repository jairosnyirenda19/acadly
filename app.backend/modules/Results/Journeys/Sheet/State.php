<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Sheet;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Entered = 'entered';
    case Submitted = 'submitted';
    case Moderated = 'moderated';
    case Approved = 'approved';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Entered => 'Marks being entered',
            self::Submitted => 'Waiting for moderation',
            self::Moderated => 'Waiting for the board',
            self::Approved => 'Approved by the board',
            self::Released => 'Released',
        };
    }
}
