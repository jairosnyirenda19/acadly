<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Contracts;

/**
 * The share of lectures a student must attend to sit the examinations, as
 * the division sets it (D-113). Answered by the module that keeps the bar;
 * Lectures shows each course against it and never assumes a number.
 */
interface AttendanceBar
{
    /** The bar as a percentage of lectures held; 0 when the division has none. */
    public function percent(string $divisionId): int;
}
