<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Programmes\Application\Registration\RegistrationChecks;
use Acadly\Modules\Programmes\Domain\Registration;

/** Every check passes: the courses needed first, the elective groups, the load and the seats. */
final class CoursesInOrder implements Gate
{
    public function __construct(private readonly RegistrationChecks $checks) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $r = $context->subject;

        if (! $r instanceof Registration) {
            return GateResult::blocked('This is not a semester registration.', 'Open it from Registration.');
        }

        $problems = $this->checks->run($r)['problems'];

        return $problems === []
            ? GateResult::ok()
            : GateResult::blocked($problems[0]['text'], count($problems) > 1 ? 'Put right the '.count($problems).' problems listed under Checks.' : 'Change the courses, then send it again.');
    }
}
