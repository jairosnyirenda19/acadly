<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\GuardianLink;
use Acadly\Core\Enrolment\Journeys\GuardianLink\OutcomeId as O;
use Acadly\Core\Enrolment\Journeys\GuardianLink\State as St;
use Acadly\Core\Enrolment\Journeys\GuardianLink\StepId as S;
use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;

/*
 * A parent's link to a learner (03 §4, D-082). The school's contact list
 * proposes it; the parent confirms it; the office may suspend or end it.
 */
return JourneyBlueprint::make('enrolment.guardian_link@2026.1')
    ->title('Parent link')
    ->subject(GuardianLink::class)
    ->states(St::class, initial: St::Requested, terminal: [St::Revoked])
    ->stage('parent', 'Parent', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Confirm)
                ->title('Confirm the child')
                ->from(St::Requested)
                ->byHolder()
                ->outcome(Outcome::make(O::Confirmed)->title('This is my child')->transition(Transition::to(St::Active)))
                ->outcome(Outcome::make(O::NotMine)->title('This is not my child')->transition(Transition::to(St::Disputed)))
        )
    )
    ->stage('office', 'Office', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Suspend)
                ->title('Suspend access')
                ->from(St::Active)
                ->capability(EnrolmentPermission::Families)
                ->outcome(Outcome::make(O::Suspended)->title('Suspend')->requiresReason()->transition(Transition::to(St::Suspended)))
        )
        ->step(
            Step::make(S::Reinstate)
                ->title('Restore access')
                ->from(St::Suspended)
                ->capability(EnrolmentPermission::Families)
                ->outcome(Outcome::make(O::Reinstated)->title('Restore')->requiresReason()->transition(Transition::to(St::Active)))
        )
        ->step(
            Step::make(S::Recheck)
                ->title('Ask the parent again')
                ->from(St::Disputed)
                ->capability(EnrolmentPermission::Families)
                ->outcome(Outcome::make(O::AskedAgain)->title('Ask again')->requiresReason()->transition(Transition::to(St::Requested)))
        )
        ->step(
            Step::make(S::Revoke)
                ->title('End the link')
                ->from(St::Requested, St::Active, St::Suspended, St::Disputed)
                ->capability(EnrolmentPermission::Families)
                ->outcome(Outcome::make(O::Revoked)->title('End')->requiresReason()->transition(Transition::to(St::Revoked)))
        )
    );
