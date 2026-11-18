<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Journeys\SeriousIncident;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Conduct\Domain\ConductCase;
use Illuminate\Support\Facades\DB;

/** Every learner in the incident has an outcome: only then is the incident decided. */
final class EveryLearnerHasAnOutcome implements Gate
{
    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $case = $context->subject;

        if (! $case instanceof ConductCase) {
            return GateResult::blocked('This is not a serious incident.', 'Open the incident from Conduct.');
        }

        $learners = DB::table('conduct_entry_learners')->where('entry_id', $case->entry_id)->count();
        $given = DB::table('conduct_outcomes')->where('case_id', $case->id)->distinct()->count('learner_id');

        return $learners > 0 && $given >= $learners
            ? GateResult::ok()
            : GateResult::blocked('A learner in the incident has no outcome yet.', 'Give an outcome for each learner.');
    }
}
