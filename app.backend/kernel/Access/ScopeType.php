<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/** Where a role is held. A wider scope covers the narrower ones inside it. */
enum ScopeType: string
{
    case Institution = 'institution';
    case Division = 'division';
    case Campus = 'campus';
    case ClassGroup = 'class';
}
