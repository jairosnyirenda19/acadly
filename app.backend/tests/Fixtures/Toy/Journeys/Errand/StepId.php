<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

enum StepId: string
{
    case Submit = 'submit';
    case Decide = 'decide';
    case Complete = 'complete';
    case Reopen = 'reopen';
}
