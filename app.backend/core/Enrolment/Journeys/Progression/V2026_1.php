<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\ProgressionDecision;
use Acadly\Core\Enrolment\Journeys\Progression\ApplyOutcome;
use Acadly\Core\Enrolment\Journeys\Progression\NextYearStarted;
use Acadly\Core\Enrolment\Journeys\Progression\OutcomeId as O;
use Acadly\Core\Enrolment\Journeys\Progression\State as St;
use Acadly\Core\Enrolment\Journeys\Progression\StepId as S;
use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;

/*
 * A learner's year-end outcome (09 §4.3, D-088). The office decides it at
 * the year-end run; the receiving level group confirms an intake; it takes
 * effect on the first day of the next school year.
 */
return JourneyBlueprint::make('enrolment.progression@2026.1')
    ->title('Year-end outcome')
    ->subject(ProgressionDecision::class)
    ->states(St::class, initial: St::Proposed, terminal: [St::Effective, St::Cancelled])
    ->stage('run', 'Year-end run', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Decide)
                ->title('Decide the outcome')
                ->from(St::Proposed)
                ->capability(EnrolmentPermission::Manage)
                ->outcome(Outcome::make(O::Promoted)->title('Promoted')->transition(Transition::to(St::Decided)))
                ->outcome(Outcome::make(O::Repeated)->title('Repeat')->requiresReason()->transition(Transition::to(St::Decided)))
                ->outcome(Outcome::make(O::Completed)->title('Completed')->transition(Transition::to(St::Decided)))
                ->outcome(Outcome::make(O::Left)->title('Leave')->requiresReason()->transition(Transition::to(St::Decided)))
                ->outcome(Outcome::make(O::Progressed)->title('To the next level group')->transition(Transition::to(St::AwaitingIntake)))
        )
    )
    ->stage('intake', 'Intake', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::ConfirmIntake)
                ->title('Confirm the intake')
                ->from(St::AwaitingIntake)
                ->capability(EnrolmentPermission::Manage)
                ->outcome(Outcome::make(O::Accepted)->title('Confirm')->transition(Transition::to(St::Decided)))
                ->outcome(Outcome::make(O::TurnedBack)->title('Turn back')->requiresReason()->transition(Transition::to(St::Proposed)))
        )
    )
    ->stage('effect', 'Next year', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::TakeEffect)
                ->title('Take effect')
                ->from(St::Decided)
                ->gate(NextYearStarted::class)
                ->capability(EnrolmentPermission::Manage)
                ->outcome(Outcome::make(O::Applied)->title('In effect')->effect(ApplyOutcome::class)->transition(Transition::to(St::Effective)))
        )
        ->step(
            Step::make(S::Cancel)
                ->title('Cancel the outcome')
                ->from(St::Proposed, St::Decided, St::AwaitingIntake)
                ->capability(EnrolmentPermission::Manage)
                ->outcome(Outcome::make(O::Cancelled)->title('Cancel')->requiresReason()->transition(Transition::to(St::Cancelled)))
        )
    );
