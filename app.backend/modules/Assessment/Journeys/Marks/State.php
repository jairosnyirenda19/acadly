<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Marks;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Marking = 'marking';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Marking => 'Marking',
            self::Submitted => 'Submitted',
            self::Returned => 'Returned',
            self::Locked => 'Locked',
        };
    }

    /** Marks can be entered: the assessment is being marked, or came back to the teacher. */
    public function open(): bool
    {
        return $this === self::Marking || $this === self::Returned;
    }
}
