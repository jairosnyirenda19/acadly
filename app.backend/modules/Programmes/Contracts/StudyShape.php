<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/** How a division sets what its learners study. */
enum StudyShape: string
{
    /** Levels and classes with subjects, as in a school. */
    case Classes = 'classes';

    /** Programmes with a curriculum of courses, as in a college. */
    case Programmes = 'programmes';
}
