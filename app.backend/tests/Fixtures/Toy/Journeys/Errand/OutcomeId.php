<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

enum OutcomeId: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Declined = 'declined';
    case Done = 'done';
    case Reopened = 'reopened';
}
