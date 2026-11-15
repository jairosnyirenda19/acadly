<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Declined = 'declined';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Done => 'Done',
        };
    }
}
