<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Journeys\Pickup;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\SafeRelease\Application\Collectors;
use Acadly\Modules\SafeRelease\Domain\Pickup;

/**
 * The person at the gate is someone the family named for this day: a contact
 * who is not barred, a collector for good, or a one-day collector whose code
 * was checked.
 */
final class CollectorIsNamed implements Gate
{
    public function __construct(private readonly Collectors $collectors) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $pickup = $context->subject;

        if (! $pickup instanceof Pickup) {
            return GateResult::blocked('This is not a pick-up.', 'Open the child from Pick-up.');
        }

        $found = null;

        foreach ($this->collectors->mayCollect($pickup->learner_id, $pickup->day->toDateString()) as $c) {
            if ($c['kind'] === $pickup->collector_kind && $c['id'] === $pickup->collector_ref) {
                $found = $c;
            }
        }

        if ($found === null) {
            return GateResult::blocked('This person is not on the list of who may collect the child.', 'Do not release the child. Call the family.');
        }

        if ($found['needs_code'] && ! $pickup->code_checked) {
            return GateResult::blocked('The code for today was not checked.', 'Ask for the code the family was given.');
        }

        return GateResult::ok();
    }
}
