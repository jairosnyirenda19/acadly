<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/**
 * The words a division uses for the things every school has (02 §2.1). A
 * Profile Blueprint gives each its singular and plural; screens, notices and
 * documents take them from the division, never from code.
 */
enum Term: string
{
    /** Child, Pupil, Student. */
    case Learner = 'learner';

    /** Room, Standard, Form. A rung of the ladder. */
    case Level = 'level';

    /** Class, Stream. The group a learner sits in. */
    case Group = 'group';

    /** Term, Semester. */
    case Period = 'period';
}
