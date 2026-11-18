<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\Progression;

use Acadly\Core\Enrolment\Domain\ProgressionDecision;
use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;

/** The school year the outcome is for has started: until then the learner stays in this year's class. */
final class NextYearStarted implements Gate
{
    public function __construct(private readonly Clock $clock) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $decision = $context->subject;

        if (! $decision instanceof ProgressionDecision) {
            return GateResult::blocked('This is not a year-end outcome.', 'Open the outcome from the year-end run.');
        }

        $starts = DB::table('progression_runs as r')->join('academic_years as y', 'y.id', '=', 'r.to_year_id')->where('r.id', $decision->run_id)->value('y.starts_on');

        return is_string($starts) && substr($starts, 0, 10) <= $this->clock->today()
            ? GateResult::ok()
            : GateResult::blocked('The next school year has not started.', 'The outcome takes effect on its first day.');
    }
}
