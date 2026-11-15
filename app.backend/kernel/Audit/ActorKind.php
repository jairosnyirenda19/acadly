<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** Who performed the action. `anonymous` is a request with no account, such as a failed sign-in. */
enum ActorKind: string
{
    case User = 'user';
    case System = 'system';
    case Support = 'support';
    case Integration = 'integration';
    case Anonymous = 'anonymous';
}
