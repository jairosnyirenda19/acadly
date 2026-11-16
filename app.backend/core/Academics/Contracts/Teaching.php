<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

/**
 * What other domains may know of subjects, who teaches them, and the terms of
 * each level (the approved Subjects and Teaching screens). Read-only.
 *
 * @phpstan-type Period array{id: string, name: string, division_id: string, year_id: string, starts_on: string, ends_on: string}
 * @phpstan-type Subject array{id: string, name: string, short: string, division_id: string}
 */
interface Teaching
{
    /**
     * A term or semester by id.
     *
     * @return Period|null
     */
    public function period(string $id): ?array;

    /**
     * The term of a level that holds the day, or null when the day is in none.
     *
     * @return Period|null
     */
    public function periodOn(string $divisionId, string $day): ?array;

    /**
     * The term of a level that holds the day, or the last one that began before it.
     *
     * @return Period|null
     */
    public function periodAt(string $divisionId, string $day): ?array;

    /**
     * Subjects by id, retired ones too.
     *
     * @param  list<string>  $ids
     * @return array<string, Subject>
     */
    public function subjects(array $ids): array;

    /**
     * The subjects in force that a level of a division takes, in order.
     *
     * @return list<Subject>
     */
    public function subjectsFor(string $divisionId, string $levelCode): array;

    /** Whether the account is given the subject in the class now. */
    public function teaches(string $userId, string $classId, string $subjectId): bool;

    /**
     * The subjects the account is given now, class by class.
     *
     * @return list<array{class_id: string, subject_id: string}>
     */
    public function taughtBy(string $userId): array;

    /**
     * Who is given each subject of these classes now.
     *
     * @param  list<string>  $classIds
     * @return list<array{class_id: string, subject_id: string, user_id: string}>
     */
    public function teachersOf(array $classIds): array;
}
