<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

/** What other domains may know of the academic calendar and the classes. */
interface AcademicCalendar
{
    /**
     * The year that contains the given day, or the first year when none does,
     * with the terms of each level (division id => terms in order).
     *
     * @return array{id: string, name: string, starts_on: string, ends_on: string, terms: array<string, list<array{id: string, name: string, starts_on: string, ends_on: string}>>}|null
     */
    public function currentYear(?string $day = null): ?array;

    /** Classes in force in a year, across the school. */
    public function classCount(string $yearId): int;

    /** Whether every offering of the school has at least one class in the year. */
    public function everyOfferingHasClasses(string $yearId): bool;

    /**
     * A class in force, or null when it does not exist or has ended.
     *
     * @return array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}|null
     */
    public function classInForce(string $classId): ?array;

    /**
     * The classes in force in a year, in the order of their divisions, campuses and positions.
     *
     * @return list<array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>
     */
    public function classesInYear(string $yearId): array;

    /**
     * The names of classes, ended ones too, with the name of their year.
     *
     * @param  list<string>  $classIds
     * @return array<string, array{name: string, year: string}>
     */
    public function classNames(array $classIds): array;

    /** The account given the class as its class teacher, now. */
    public function classTeacherId(string $classId): ?string;
}
