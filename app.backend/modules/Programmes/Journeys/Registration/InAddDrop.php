<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Programmes\Application\Registration\Windows;
use Acadly\Modules\Programmes\Domain\Registration;

/** Courses change after registration only until the end of add and drop. */
final class InAddDrop implements Gate
{
    public function __construct(private readonly Windows $windows) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $r = $context->subject;

        if (! $r instanceof Registration) {
            return GateResult::blocked('This is not a semester registration.', 'Open it from Registration.');
        }

        return $this->windows->addDropOpen($r->period_id)
            ? GateResult::ok()
            : GateResult::blocked('Add and drop for this semester has ended.', 'Change the dates on the registration desk.');
    }
}
