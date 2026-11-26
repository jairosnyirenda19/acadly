<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Contracts;

/**
 * What term fees need to know about college students (D-116): the programmes
 * of a division, and each student's programme, mode of study and the credits
 * registered for a period. Answered by the module that keeps programmes.
 */
interface StudentProgrammes
{
    /**
     * The programmes of a division that fees can be set for.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function programmes(string $divisionId): array;

    /**
     * Each student's programme, mode ('full_time' or 'part_time') and the credits of their
     * approved registration for the period (null while none is approved).
     *
     * @param  list<string>  $learnerIds
     * @return array<string, array{programme_id: string, mode: string, credits: int|null}>
     */
    public function students(array $learnerIds, string $periodId): array;
}
