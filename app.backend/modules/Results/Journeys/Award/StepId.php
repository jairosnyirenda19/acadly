<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Award;

enum StepId: string
{
    case Approve = 'approve';
    case Confirm = 'confirm';
    case Confer = 'confer';
}
