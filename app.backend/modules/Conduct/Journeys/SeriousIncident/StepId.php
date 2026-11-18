<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Journeys\SeriousIncident;

enum StepId: string
{
    case TakeUp = 'take_up';
    case Decide = 'decide';
    case MeetFamily = 'meet_family';
    case Close = 'close';
    case Withdraw = 'withdraw';
}
