<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

/** What other domains may know of the academic calendar and the classes. */
interface AcademicCalendar
{
    /**
     * The year that contains the given day, or the first year when none does.
     *
     * @return array{id: string, name: string, starts_on: string, ends_on: string, periods: list<array{id: string, name: string, starts_on: string, ends_on: string}>}|null
     */
    public function currentYear(?string $day = null): ?array;

    /** Classes in force in a year, across the school. */
    public function classCount(string $yearId): int;

    /** Whether every offering of the school has at least one class in the year. */
    public function everyOfferingHasClasses(string $yearId): bool;
}
