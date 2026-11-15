<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/** How a division divides its academic year. */
enum PeriodModel: string
{
    case Terms = 'terms';
    case Semesters = 'semesters';
}
