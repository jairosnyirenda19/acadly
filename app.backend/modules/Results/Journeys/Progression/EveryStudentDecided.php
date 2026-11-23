<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Journeys\Progression;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Results\Application\Progression;
use Acadly\Modules\Results\Domain\ProgressionList;

/** Every student has a decision: the rules give one, or the board chose one. */
final class EveryStudentDecided implements Gate
{
    public function __construct(private readonly Progression $progression) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $list = $context->subject;

        if (! $list instanceof ProgressionList) {
            return GateResult::blocked('This is not a progression list.', 'Open it from the examiners\' board.');
        }

        $open = count(array_filter($this->progression->rows($list), fn (array $r): bool => $r['decision'] === null));

        return $open === 0
            ? GateResult::ok()
            : GateResult::blocked($open === 1 ? '1 student has no decision yet.' : "{$open} students have no decision yet.", 'Choose a decision for each student the rules leave to the board.');
    }
}
