<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\Progression;

enum StepId: string
{
    case Decide = 'decide';
    case ConfirmIntake = 'confirm_intake';
    case TakeEffect = 'take_effect';
    case Cancel = 'cancel';
}
