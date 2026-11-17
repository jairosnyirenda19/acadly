<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\GuardianLink;

enum OutcomeId: string
{
    case Confirmed = 'confirmed';
    case NotMine = 'not_mine';
    case Suspended = 'suspended';
    case Reinstated = 'reinstated';
    case AskedAgain = 'asked_again';
    case Revoked = 'revoked';
}
