<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

/** Which layer a manifest belongs to. */
enum Tier: string
{
    /** Always on. Every institution has it. */
    case Core = 'core';

    /** Switched on per division, by blueprint, subscription and the school. */
    case Optional = 'optional';
}
