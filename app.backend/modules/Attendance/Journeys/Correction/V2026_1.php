<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Domain\Correction;
use Acadly\Modules\Attendance\Journeys\Correction\MarkStillCurrent;
use Acadly\Modules\Attendance\Journeys\Correction\Milestone;
use Acadly\Modules\Attendance\Journeys\Correction\OutcomeId as O;
use Acadly\Modules\Attendance\Journeys\Correction\RecordCorrectedMark;
use Acadly\Modules\Attendance\Journeys\Correction\State as St;
use Acadly\Modules\Attendance\Journeys\Correction\StepId as S;

return JourneyBlueprint::make('attendance.correction@2026.1')
    ->title('Register correction')
    ->subject(Correction::class)
    ->states(St::class, initial: St::Drafted, terminal: [St::Approved, St::Declined])
    ->milestone(Milestone::Decided, St::Approved, St::Declined)
    ->stage('request', 'Request', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Request)
                ->title('Ask for a correction')
                ->from(St::Drafted)
                ->gate(MarkStillCurrent::class)
                ->capability(AttendancePermission::Capture)
                ->outcome(Outcome::make(O::Requested)->title('Ask for the correction')->requiresReason()->transition(Transition::to(St::Requested)))
        )
    )
    ->stage('decision', 'Decision', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Decide)
                ->title('Decide on the correction')
                ->from(St::Requested)
                ->gate(MarkStillCurrent::class)
                ->capability(AttendancePermission::Correct)
                ->fourEyes(S::Request)
                ->outcome(
                    Outcome::make(O::Approved)->title('Approve')
                        ->effect(RecordCorrectedMark::class)
                        ->transition(Transition::to(St::Approved))
                )
                ->outcome(
                    Outcome::make(O::Declined)->title('Decline')
                        ->requiresReason()
                        ->transition(Transition::to(St::Declined))
                )
        )
    );
