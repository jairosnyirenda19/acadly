<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/** How a level records learning: bound by its profile to `AssessmentSlot::Model`. */
enum AssessmentModel: string
{
    /** Assessments with marks, and term grades worked out from them. */
    case Marks = 'marks';

    /** Observations against learning areas, with no marks, and a progress review each term (D-094). */
    case Observational = 'observational';

    public function takesMarks(): bool
    {
        return $this === self::Marks;
    }
}
