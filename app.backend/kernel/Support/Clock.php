<?php

declare(strict_types=1);

namespace Acadly\Kernel\Support;

use Carbon\CarbonImmutable;

/**
 * The one source of the current time for domain code (rule B8). Tests move
 * time with Carbon::setTestNow(); production reads the system clock.
 */
final class Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * The calendar day where the school is, as YYYY-MM-DD. Moments are kept
     * in UTC; a day (a register, a date admitted) is the school's own day.
     */
    public function today(): string
    {
        return $this->now()->setTimezone(config()->string('acadly.timezone', 'UTC'))->toDateString();
    }
}
