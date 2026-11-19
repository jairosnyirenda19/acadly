<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Journeys\Pickup;

enum StepId: string
{
    case Verify = 'verify';
    case Release = 'release';
    case Dispute = 'dispute';
    case Cancel = 'cancel';
}
