<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

enum OutcomeId: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Registered = 'registered';
    case Reopened = 'reopened';
    case Withdrawn = 'withdrawn';
}
