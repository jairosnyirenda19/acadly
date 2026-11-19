<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Registered = 'registered';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Courses being chosen',
            self::Submitted => 'Waiting for approval',
            self::Approved => 'Waiting for fees',
            self::Registered => 'Registered',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
