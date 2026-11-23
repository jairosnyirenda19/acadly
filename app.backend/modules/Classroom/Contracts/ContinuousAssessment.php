<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Contracts;

/**
 * What Results reads from Classroom (D-105): a course's share for continuous
 * assessment and each student's weighted total from released marks, out of
 * that share. Work never handed in counts 0 once its marks are released.
 */
interface ContinuousAssessment
{
    /**
     * @return array{share: int, totals: array<string, float>, unreleased: int}
     */
    public function of(string $offeringId): array;

    /**
     * One student's released work in a course, each with its weight and the part of the share it earned.
     *
     * @return list<array{title: string, weight: float, mark: float|null, out_of: int, earned: float}>
     */
    public function parts(string $offeringId, string $learnerId): array;
}
