<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\ConductCase;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\EveryLearnerHasAnOutcome;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\OutcomeId as O;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State as St;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\StepId as S;

/*
 * A serious incident (D-092): reported by the person who saw it, looked
 * into and decided by someone who may decide serious incidents (never the
 * person who reported it), the family met, then closed.
 */
return JourneyBlueprint::make('conduct.serious_incident@2026.1')
    ->title('Serious incident')
    ->subject(ConductCase::class)
    ->states(St::class, initial: St::Reported, terminal: [St::Closed, St::Withdrawn])
    ->stage('look', 'Looked into', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::TakeUp)
                ->title('Take it up')
                ->from(St::Reported)
                ->capability(ConductPermission::FollowUp)
                ->outcome(Outcome::make(O::TakenUp)->title('Take it up')->transition(Transition::to(St::LookedInto)))
        )
    )
    ->stage('decide', 'Decided', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Decide)
                ->title('Decide')
                ->from(St::LookedInto)
                ->gate(EveryLearnerHasAnOutcome::class)
                ->capability(ConductPermission::FollowUp)
                ->outcome(Outcome::make(O::Decided)->title('Decided')->transition(Transition::to(St::Decided)))
        )
    )
    ->stage('family', 'Family', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::MeetFamily)
                ->title('Meet the family')
                ->from(St::Decided)
                ->capability(ConductPermission::FollowUp)
                ->outcome(Outcome::make(O::Met)->title('Family met')->transition(Transition::to(St::FamilyMet)))
                ->outcome(Outcome::make(O::Told)->title('Family told, not met')->requiresReason()->transition(Transition::to(St::FamilyMet)))
        )
        ->step(
            Step::make(S::Close)
                ->title('Close')
                ->from(St::FamilyMet)
                ->capability(ConductPermission::FollowUp)
                ->outcome(Outcome::make(O::Closed)->title('Close')->transition(Transition::to(St::Closed)))
        )
        ->step(
            Step::make(S::Withdraw)
                ->title('Withdraw')
                ->from(St::Reported, St::LookedInto)
                ->capability(ConductPermission::FollowUp)
                ->outcome(Outcome::make(O::Withdrawn)->title('Withdraw')->requiresReason()->transition(Transition::to(St::Withdrawn)))
        )
    );
