<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\CorrectionApproval;
use Acadly\Modules\Attendance\Application\Marks;
use Acadly\Modules\Attendance\Application\Reach;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Domain\Correction;
use Acadly\Modules\Attendance\Journeys\Correction\OutcomeId;
use Acadly\Modules\Attendance\Journeys\Correction\State;
use Acadly\Modules\Attendance\Journeys\Correction\StepId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections to a submitted register (the approved design). A request is
 * a new record on the `attendance.correction` journey; the request and the
 * decision are steps of the journey engine, which checks the permission at
 * the class, and that the person who decides is not the person who asked.
 */
#[NotAudited('Each request and decision is a journey step, and the journey engine writes its audit line.')]
final class CorrectionActions
{
    public function __construct(
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly Marks $marks,
        private readonly Reach $reach,
        private readonly ApprovalActions $approvals,
    ) {}

    public function request(User $by, string $registerId, string $learnerId, string $to, string $reason): Correction
    {
        $register = DB::table('attendance_registers')->where('id', $registerId)->first(['id', 'class_id']);

        if ($register === null || ! $this->reach->sees($by, Row::text($register, 'class_id'))) {
            throw (new ModelNotFoundException)->setModel(Correction::class);
        }

        $from = $this->marks->current($registerId, $learnerId);

        if ($from === null) {
            throw ValidationException::withMessages(['learner_id' => ['This learner is not in the register.']]);
        }

        if (Mark::tryFrom($to) === null || $to === $from) {
            throw ValidationException::withMessages(['to' => ['Choose a mark other than the one in force.']]);
        }

        if (trim($reason) === '' || mb_strlen(trim($reason)) > 255) {
            throw ValidationException::withMessages(['reason' => ['Say why the mark should change, up to 255 characters.']]);
        }

        $open = Correction::query()->where('register_id', $registerId)->where('learner_id', $learnerId)
            ->whereIn('state', [State::Drafted->value, State::Requested->value])->exists();

        if ($open) {
            throw ValidationException::withMessages(['learner_id' => ['A correction for this learner is already awaiting approval.']]);
        }

        return DB::transaction(function () use ($by, $register, $registerId, $learnerId, $from, $to, $reason): Correction {
            $correction = Correction::query()->create([
                'register_id' => $registerId, 'class_id' => Row::text($register, 'class_id'), 'learner_id' => $learnerId,
                'from_status' => $from, 'to_status' => $to, 'reason' => trim($reason), 'requested_by' => $by->id,
                'state' => State::Drafted->value, 'journey_ref' => (string) $this->blueprints->newest('attendance.correction'),
            ]);
            $this->engine->perform($correction, $by, StepId::Request, OutcomeId::Requested, ['reason' => trim($reason)]);
            // Who decides is the school's chain for register corrections (Settings › Approvals).
            $this->approvals->open(CorrectionApproval::KEY, $correction->id);

            return $correction;
        });
    }

    /**
     * Answers the step of the correction's approval that asks this person. The
     * last yes, or any no, performs the journey's decision (CorrectionApproval).
     */
    public function decide(User $by, string $correctionId, bool $approve, string $note): Correction
    {
        $correction = Correction::query()->find($correctionId);

        if (! $correction instanceof Correction || ! $this->reach->sees($by, $correction->class_id)) {
            throw (new ModelNotFoundException)->setModel(Correction::class);
        }

        if ($correction->state !== State::Requested->value) {
            throw ValidationException::withMessages(['correction' => ['This correction has already been decided.']]);
        }

        // A correction asked for before approvals existed gets its chain now.
        $request = $this->approvals->open(CorrectionApproval::KEY, $correction->id);
        $this->approvals->answer($by, $request, $approve, trim($note) !== '' ? trim($note) : null);

        return $correction->refresh();
    }
}
