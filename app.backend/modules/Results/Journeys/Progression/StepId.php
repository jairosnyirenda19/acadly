<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Progression;

enum StepId: string
{
    case Decide = 'decide';
    case Reopen = 'reopen';
    case Apply = 'apply';
}
