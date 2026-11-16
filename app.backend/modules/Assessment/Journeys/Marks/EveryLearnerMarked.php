<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Marks;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Domain\Assessment;

/**
 * Every learner in the class on the day has a mark or a stated reason. Where
 * the part the assessment counts in asks the teacher to decide, a learner
 * marked absent needs a mark, or to be marked excused or exempt.
 */
final class EveryLearnerMarked implements Gate
{
    public function __construct(private readonly Sheets $sheets) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $assessment = $context->subject;

        if (! $assessment instanceof Assessment) {
            return GateResult::blocked('This is not an assessment.', 'Open the marks sheet of the assessment.');
        }

        $left = $this->sheets->unmarked($assessment);

        if ($left['missing'] > 0) {
            return GateResult::blocked(
                $left['missing'] === 1 ? '1 learner has neither a mark nor a reason.' : "{$left['missing']} learners have neither a mark nor a reason.",
                'Give each learner a mark, or say why there is none: absent, excused or exempt.',
            );
        }

        if ($left['undecided'] > 0) {
            return GateResult::blocked(
                $left['undecided'] === 1 ? '1 learner is marked absent, and the grading plan asks you to decide.' : "{$left['undecided']} learners are marked absent, and the grading plan asks you to decide.",
                'Give each a mark, or mark them excused or exempt.',
            );
        }

        return GateResult::ok();
    }
}
