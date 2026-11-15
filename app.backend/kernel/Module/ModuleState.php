<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

/**
 * Why a module is or is not on (docs/architecture/01-foundations.md §6).
 * The first condition that fails names the state.
 */
enum ModuleState: string
{
    case Active = 'active';
    case NotInstalled = 'not_installed';
    case NotOffered = 'not_offered';
    case NotLicensed = 'not_licensed';
    case SwitchedOff = 'switched_off';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
