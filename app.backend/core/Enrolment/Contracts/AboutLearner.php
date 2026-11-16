<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * A notice about one learner. Its party is the learner's contacts: the main
 * contact, or every contact when the school chose so for the notice.
 */
interface AboutLearner
{
    public function learnerId(): string;
}
