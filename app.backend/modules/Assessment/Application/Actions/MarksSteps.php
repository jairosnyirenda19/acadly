<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\LockApproval;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Domain\Assessment;
use Acadly\Modules\Assessment\Journeys\Marks\OutcomeId;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly ApprovalActions $approvals,
    ) {}

    /** The teacher whose assessment it is submits its marks. */
    public function submit(User $by, string $id): Assessment
    {
        $a = $this->sheets->find($by, $id);

        if (! $this->reach->owns($by, $a)) {
            throw new AuthorizationException('Access is refused.');
        }

        DB::transaction(function () use ($a, $by): void {
            $this->engine->perform($a, $by, StepId::Submit, OutcomeId::Submitted);
            // Who locks is the school's chain for locking marks (Settings › Approvals).
            $this->approvals->open(LockApproval::KEY, $a->id);
        });

        return $a;
    }

    /** A yes to the step of the lock's approval that asks this person; the last yes locks the marks. */
    public function lock(User $by, string $id): Assessment
    {
        return $this->answer($by, $id, true, null);
    }

    /** A no: the marks go back to the teacher with the reason. */
    public function return(User $by, string $id, string $reason): Assessment
    {
        return $this->answer($by, $id, false, trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null);
    }

    private function answer(User $by, string $id, bool $approve, ?string $reason): Assessment
    {
        $a = $this->sheets->find($by, $id);

        if ($a->state !== State::Submitted->value) {
            throw ValidationException::withMessages(['assessment' => ['These marks are not waiting to be locked.']]);
        }

        // Marks submitted before approvals existed get their chain now.
        $request = $this->approvals->open(LockApproval::KEY, $a->id);
        $this->approvals->answer($by, $request, $approve, $reason);

        return $a->refresh();
    }
}
