<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

enum StepId: string
{
    case Submit = 'submit';
    case SubmitOwn = 'submit_own';
    case Approve = 'approve';
    case Register = 'register';
    case Change = 'change';
    case Withdraw = 'withdraw';
}
