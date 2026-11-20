<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/**
 * Where a student account sits in a college (D-101), for other modules that
 * address students by their place: News sends to a faculty, a department, a
 * programme or a year of study.
 *
 * @phpstan-type Place array{division_id: string, faculty_id: string, department_id: string, programme_id: string, year_of_study: int}
 */
interface StudentPlaces
{
    /**
     * The place of the student whose account this is, or null when it is not a student's.
     *
     * @return Place|null
     */
    public function of(string $userId): ?array;

    /**
     * The faculties, departments and programmes of a division, by name, for choosing an audience.
     *
     * @return array{faculties: list<array{id: string, name: string}>, departments: list<array{id: string, name: string}>, programmes: list<array{id: string, name: string}>, years: list<int>}
     */
    public function structure(string $divisionId): array;

    /** How many active students an audience reaches. */
    public function count(string $divisionId, string $audience, ?string $id, ?int $year): int;
}
