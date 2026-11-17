<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\GuardianLink;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Requested = 'requested';
    case Active = 'active';
    case Disputed = 'disputed';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting the parent',
            self::Active => 'Active',
            self::Disputed => 'Not their child',
            self::Suspended => 'Suspended',
            self::Revoked => 'Ended',
        };
    }
}
