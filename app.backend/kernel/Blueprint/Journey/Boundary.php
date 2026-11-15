<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/**
 * Where an effect runs. Internal effects run inside the transaction of the
 * step. After-commit effects (messages, calls to other systems) run on the
 * queue once the step is committed (rule B6).
 */
enum Boundary: string
{
    case Internal = 'internal';
    case AfterCommit = 'after_commit';
}
