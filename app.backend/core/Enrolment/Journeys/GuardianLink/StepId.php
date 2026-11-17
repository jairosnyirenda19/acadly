<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\GuardianLink;

enum StepId: string
{
    case Confirm = 'confirm';
    case Suspend = 'suspend';
    case Reinstate = 'reinstate';
    case Recheck = 'recheck';
    case Revoke = 'revoke';
}
