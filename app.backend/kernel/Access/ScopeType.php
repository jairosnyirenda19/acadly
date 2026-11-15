<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/**
 * Where a role is held. A wider scope covers the narrower ones inside it:
 * the institution covers everything; a division and a campus each cover the
 * offerings that join them (a division taught at a campus); an offering covers
 * its classes.
 */
enum ScopeType: string
{
    case Institution = 'institution';
    case Division = 'division';
    case Campus = 'campus';
    case Offering = 'offering';
    case ClassGroup = 'class';
}
