<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Journeys\Registration;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Programmes\Application\Registration\FeesClearance;
use Acadly\Modules\Programmes\Domain\Registration;

/** The share of fees the college needs is paid, or registration was allowed without it, with a reason. */
final class FeesCleared implements Gate
{
    public function __construct(private readonly FeesClearance $fees) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $r = $context->subject;

        if (! $r instanceof Registration) {
            return GateResult::blocked('This is not a semester registration.', 'Open it from Registration.');
        }

        return $this->fees->of($r)['cleared']
            ? GateResult::ok()
            : GateResult::blocked('The fees needed for registration are not paid.', 'Record the payment at the fees desk, or allow registration without it.');
    }
}
