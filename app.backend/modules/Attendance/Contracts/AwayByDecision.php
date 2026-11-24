<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Contracts;

/**
 * A decision that keeps a learner out of school for some school days, such
 * as a suspension (D-111). Tagged `AwayByDecision::TAG` by the module that
 * decides it. On each school day it covers, the register marks the learner
 * Excused, names the decision, and cannot be changed by the teacher.
 */
interface AwayByDecision
{
    public const string TAG = 'attendance.away-by-decision';

    /**
     * The decisions in force for some learners: the first day, how many
     * school days, and the last day when it was ended early.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, list<array{label: string, reference: string, starts_on: string, days: int, ended_after: string|null}>> learner id => decisions
     */
    public function decisions(array $learnerIds): array;
}
