<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Journeys\Plan;

enum StepId: string
{
    case Agree = 'agree';
    case Close = 'close';
}
