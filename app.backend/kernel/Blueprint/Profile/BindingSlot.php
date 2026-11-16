<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Profile;

use BackedEnum;

/**
 * A choice a module leaves to the profile (02 §2.4). The module declares the
 * slot and the enum of values it accepts; a profile binds a value; the module
 * picks its strategy by the bound value. Code asks the blueprint what, never
 * the level who.
 */
interface BindingSlot
{
    /** The slot's stable key, owned by the module: 'attendance.mode'. */
    public function key(): string;

    /** @return class-string<BackedEnum> the enum of values the slot accepts */
    public function accepts(): string;

    /** The value when a profile binds nothing. */
    public function fallback(): BackedEnum;
}
