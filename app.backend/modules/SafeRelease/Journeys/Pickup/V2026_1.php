<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Acadly\Modules\SafeRelease\Domain\Pickup;
use Acadly\Modules\SafeRelease\Journeys\Pickup\CollectorIsNamed;
use Acadly\Modules\SafeRelease\Journeys\Pickup\OutcomeId as O;
use Acadly\Modules\SafeRelease\Journeys\Pickup\State as St;
use Acadly\Modules\SafeRelease\Journeys\Pickup\StepId as S;

/*
 * A child collected at the gate (D-094): someone arrives, the caregiver
 * checks they are on the family's list, and the child is released. Someone
 * not on the list is refused, and the refusal stays recorded.
 */
return JourneyBlueprint::make('saferelease.pickup@2026.1')
    ->title('Pick-up')
    ->subject(Pickup::class)
    ->states(St::class, initial: St::Announced, terminal: [St::Released, St::Disputed, St::Cancelled])
    ->stage('gate', 'At the gate', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Verify)
                ->title('Check who is collecting')
                ->from(St::Announced)
                ->gate(CollectorIsNamed::class)
                ->capability(SafeReleasePermission::Release)
                ->outcome(Outcome::make(O::Verified)->title('On the list')->transition(Transition::to(St::Verified)))
        )
        ->step(
            Step::make(S::Release)
                ->title('Release the child')
                ->from(St::Verified)
                ->gate(CollectorIsNamed::class)
                ->capability(SafeReleasePermission::Release)
                ->outcome(Outcome::make(O::Released)->title('Released')->transition(Transition::to(St::Released)))
        )
        ->step(
            Step::make(S::Dispute)
                ->title('Do not release')
                ->from(St::Announced, St::Verified)
                ->capability(SafeReleasePermission::Release)
                ->outcome(Outcome::make(O::NotOnTheList)->title('Not on the list')->requiresReason()->transition(Transition::to(St::Disputed)))
        )
        ->step(
            Step::make(S::Cancel)
                ->title('Cancel')
                ->from(St::Announced, St::Verified)
                ->capability(SafeReleasePermission::Release)
                ->outcome(Outcome::make(O::Cancelled)->title('Cancelled')->requiresReason()->transition(Transition::to(St::Cancelled)))
        )
    );
