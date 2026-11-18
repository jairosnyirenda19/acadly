<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Journeys\Plan;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Raised = 'raised';
    case Reviewing = 'reviewing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Raised => 'Concern raised',
            self::Reviewing => 'Reviewing',
            self::Closed => 'Closed',
        };
    }
}
