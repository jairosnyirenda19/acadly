<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/**
 * How a scale sums up a learner across subjects. Every scale also ranks a
 * class by total marks, whatever this is.
 */
enum OverallResult: string
{
    /** The mean of the term percentages. */
    case AveragePercent = 'average_percent';

    /** The mean of the grades as numbers; the highest is best (Malawi primary, 1 to 4). */
    case AverageGrade = 'average_grade';

    /** The six best grades added as points; the lowest total is best (MSCE). */
    case BestSix = 'best_six';

    /** Whether the grades must be numbers. */
    public function needsNumbers(): bool
    {
        return $this !== self::AveragePercent;
    }
}
