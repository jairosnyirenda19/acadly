<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Programmes\Application\Registration\Windows;
use Acadly\Modules\Programmes\Domain\Registration;

/** A registration is sent for approval only from the day registration opens to the end of late registration. */
final class InWindow implements Gate
{
    public function __construct(private readonly Windows $windows) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $r = $context->subject;

        if (! $r instanceof Registration) {
            return GateResult::blocked('This is not a semester registration.', 'Open it from Registration.');
        }

        return match ($this->windows->phase($r->period_id)) {
            'open', 'late' => GateResult::ok(),
            'before' => GateResult::blocked('Registration for this semester is not open yet.', 'Wait for the opening date, or change the dates on the registration desk.'),
            'none' => GateResult::blocked('This semester has no registration dates.', 'Set the dates on the registration desk.'),
            default => GateResult::blocked('Late registration for this semester has ended.', 'Change the dates on the registration desk.'),
        };
    }
}
