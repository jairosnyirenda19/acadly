<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Journeys\Correction;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Attendance\Application\Marks;
use Acadly\Modules\Attendance\Domain\Correction;

/** The mark the correction starts from is still the mark in force: nobody changed it meanwhile. */
final class MarkStillCurrent implements Gate
{
    public function __construct(private readonly Marks $marks) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $correction = $context->subject;

        if (! $correction instanceof Correction) {
            return GateResult::blocked('This is not a register correction.', 'Open the correction from its register.');
        }

        $now = $this->marks->current($correction->register_id, $correction->learner_id);

        return $now === $correction->from_status
            ? GateResult::ok()
            : GateResult::blocked('The mark has changed since the correction was asked for.', 'Decline this correction and ask again from the register.');
    }
}
