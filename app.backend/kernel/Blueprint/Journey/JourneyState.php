<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use BackedEnum;

/**
 * The states of one journey, as a string-backed enum owned by the module.
 * The label is what people read; the value is what is stored.
 */
interface JourneyState extends BackedEnum
{
    public function label(): string;
}
