<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Sheet;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Domain\Sheet;

/** Every student who may sit has an exam mark or a recorded absence. */
final class MarksComplete implements Gate
{
    public function __construct(private readonly Sheets $sheets) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $s = $context->subject;

        if (! $s instanceof Sheet) {
            return GateResult::blocked('This is not a result sheet.', 'Open it from Results.');
        }

        $missing = $this->sheets->missing($s);

        return $missing === 0
            ? GateResult::ok()
            : GateResult::blocked($missing === 1 ? '1 student has no exam mark.' : "{$missing} students have no exam mark.", 'Enter a mark, or record the student as absent.');
    }
}
