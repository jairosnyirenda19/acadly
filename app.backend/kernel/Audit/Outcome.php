<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** How an audited attempt ended. A failed or refused attempt is a line too. */
enum Outcome: string
{
    case Done = 'done';
    case Failed = 'failed';
    case Refused = 'refused';
}
