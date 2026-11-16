<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Domain\Assessment;
use Acadly\Modules\Assessment\Journeys\Marks\OutcomeId;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Submits, locks and returns the marks of an assessment (the approved Marks
 * sheet and Marks office screens). Each is a step of `assessment.marks`; the
 * journey engine checks the gate, the permission at the class, and that the
 * person who locks is not the person who submitted.
 */
#[NotAudited('Each step is performed by the journey engine, which writes its audit line.')]
final class MarksSteps
{
    public function __construct(
        private readonly JourneyEngine $engine,
        private readonly Sheets $sheets,
        private readonly Reach $reach,
    ) {}

    /** The teacher whose assessment it is submits its marks. */
    public function submit(User $by, string $id): Assessment
    {
        $a = $this->sheets->find($by, $id);

        if (! $this->reach->owns($by, $a)) {
            throw new AuthorizationException('Access is refused.');
        }

        $this->engine->perform($a, $by, StepId::Submit, OutcomeId::Submitted);

        return $a;
    }

    public function lock(User $by, string $id): Assessment
    {
        $a = $this->sheets->find($by, $id);
        $this->engine->perform($a, $by, StepId::Lock, OutcomeId::Locked);

        return $a;
    }

    public function return(User $by, string $id, string $reason): Assessment
    {
        $a = $this->sheets->find($by, $id);
        $this->engine->perform($a, $by, StepId::Return, OutcomeId::Returned, trim($reason) !== '' ? ['reason' => mb_substr(trim($reason), 0, 255)] : []);

        return $a;
    }
}
