<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/** What other modules may ask about learners (01 §8). */
interface EnrolmentDirectory
{
    /**
     * The learners placed in a class on a day, by surname and first name.
     *
     * @return list<array{id: string, name: string, admission_number: string|null}>
     */
    public function learnersIn(string $classId, string $day): array;

    /**
     * Learners by id, with their names, for those that exist.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function names(array $ids): array;
}
