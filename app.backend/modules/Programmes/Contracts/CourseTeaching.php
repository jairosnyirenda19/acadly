<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/**
 * What the lecture timetable needs from Programmes (D-102): the offerings of
 * a semester with their lecturers, the students holding a place in each,
 * and the groups of students that study together (a programme and year).
 * A student holds a place while their registration is submitted, approved
 * or registered.
 *
 * @phpstan-type Offering array{id: string, code: string, title: string, group: string, department_id: string, department: string, head_id: string|null, lecturers: list<array{id: string, name: string}>, taken: int}
 * @phpstan-type Student array{learner_id: string, name: string, number: string, user_id: string|null, phone: string|null}
 */
interface CourseTeaching
{
    /**
     * The offerings of a semester, by course code.
     *
     * @return list<Offering>
     */
    public function offerings(string $divisionId, string $periodId): array;

    /**
     * The students holding a place in an offering, by name.
     *
     * @return list<Student>
     */
    public function students(string $offeringId): array;

    /**
     * The offerings a learner holds a place in, in a semester.
     *
     * @return list<string>
     */
    public function offeringsOf(string $learnerId, string $periodId): array;

    /**
     * The student whose account this is, or null when it is not a student's.
     *
     * @return array{learner_id: string, division_id: string}|null
     */
    public function studentOf(string $userId): ?array;

    /**
     * The groups that study together in a semester: each programme and year of study, with the offerings of its courses.
     *
     * @return list<array{id: string, label: string, offerings: list<string>}>
     */
    public function cohorts(string $divisionId, string $periodId): array;
}
