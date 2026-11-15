<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;

/** The errand has a title, no longer than the limit. */
final class TitleWithin implements Gate
{
    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $title = trim($context->subject->auditLabel());
        $max = $args instanceof Limit ? $args->max : 0;

        if ($title === '') {
            return GateResult::blocked('The errand has no title.', 'Give the errand a title.');
        }

        if (mb_strlen($title) > $max) {
            return GateResult::blocked("The title is longer than {$max} characters.", 'Shorten the title.');
        }

        return GateResult::ok();
    }
}
