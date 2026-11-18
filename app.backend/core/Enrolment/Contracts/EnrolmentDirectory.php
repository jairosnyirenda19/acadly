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

    /**
     * The contacts of a learner in force now, the main contact first, with
     * the channel each chose for notices.
     *
     * @return list<array{id: string, name: string, relationship: string, phone: string, email: string|null, notices_by: string, main: bool}>
     */
    public function contactsOf(string $learnerId): array;

    /**
     * Where a learner is now: the class of the latest placement, open or not.
     *
     * @return array{class_id: string, division_id: string, campus_id: string, year_id: string, open: bool, since: string}|null
     */
    public function placeOf(string $learnerId): ?array;

    /**
     * The other learners who share a contact's phone with this learner now:
     * brothers and sisters, for a family that pays for several children at once.
     *
     * @return list<string> learner ids
     */
    public function siblingsOf(string $learnerId): array;
}
