<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * A record part whose tab is named by the learner's level, through the
 * module's own binding: "Observations" where a level records observations,
 * "Results" where it takes marks. The record uses this name when the part
 * gives one.
 */
interface TitledForLearner
{
    public function titleFor(string $learnerId): string;
}
