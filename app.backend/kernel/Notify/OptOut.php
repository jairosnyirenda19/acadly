<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/** Whether the party a notice concerns may silence it (04 §3). */
enum OptOut: string
{
    case Allowed = 'allowed';
    case Never = 'never';
}
