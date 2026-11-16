<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Marks;

enum StepId: string
{
    case Submit = 'submit';
    case Lock = 'lock';
    case Return = 'return';
}
