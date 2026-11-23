<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * What Results needs from Programmes to apply the examiners' board's
 * decisions (D-106): a college's programmes, their students by year, the
 * core courses a student must pass, and the change of a student's year or
 * status, with the courses they carry. The caller has checked access.
 *
 * @phpstan-type ProgrammeRow array{id: string, code: string, name: string, award: string, years: int, credits: int}
 */
interface StudentProgress
{
    public const array DECISIONS = ['proceed', 'carry', 'repeat_year', 'discontinue', 'complete', 'completed'];

    /** @return list<ProgrammeRow> */
    public function programmes(string $divisionId): array;

    /**
     * The learners in a programme now, in a year of study or every year.
     *
     * @return list<string>
     */
    public function students(string $programmeId, ?int $year): array;

    /**
     * The core courses of a student's curriculum, every year and semester.
     *
     * @return list<array{course_id: string, code: string}>
     */
    public function coreCourses(string $learnerId): array;

    /**
     * Applies a decision: 'proceed' and 'carry' move to the next year of study, 'repeat_year' keeps the year,
     * 'discontinue' ends the studies, 'complete' changes nothing yet (the award list follows), and
     * 'completed' ends the programme as completed. The carried courses join the next registrations as Repeat.
     *
     * @param  list<string>  $carriedCourseIds
     */
    public function apply(User $by, string $learnerId, string $decision, array $carriedCourseIds, string $reason): void;
}
