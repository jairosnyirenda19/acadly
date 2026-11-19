<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\CoursesInOrder;
use Acadly\Modules\Programmes\Journeys\Registration\FeesCleared;
use Acadly\Modules\Programmes\Journeys\Registration\InAddDrop;
use Acadly\Modules\Programmes\Journeys\Registration\InWindow;
use Acadly\Modules\Programmes\Journeys\Registration\OutcomeId as O;
use Acadly\Modules\Programmes\Journeys\Registration\State as St;
use Acadly\Modules\Programmes\Journeys\Registration\StepId as S;

/*
 * A semester registration (D-098): courses are chosen, the department
 * approves, the fees needed are cleared, and the student is registered.
 * In add and drop the courses are reopened as a new version; the first stays.
 */
return JourneyBlueprint::make('programmes.registration@2026.1')
    ->title('Semester registration')
    ->subject(Registration::class)
    ->states(St::class, initial: St::Draft, terminal: [St::Withdrawn])
    ->stage('register', 'Registration', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Submit)
                ->title('Send for approval')
                ->from(St::Draft)
                ->gate(InWindow::class)
                ->gate(CoursesInOrder::class)
                ->capability(ProgrammesPermission::Register)
                ->outcome(Outcome::make(O::Submitted)->title('Sent for approval')->transition(Transition::to(St::Submitted)))
        )
        ->step(
            Step::make(S::Approve)
                ->title('Approve the courses')
                ->from(St::Submitted)
                ->capability(ProgrammesPermission::Approve)
                ->outcome(Outcome::make(O::Approved)->title('Approved')->transition(Transition::to(St::Approved)))
                ->outcome(Outcome::make(O::Returned)->title('Returned')->requiresReason()->transition(Transition::to(St::Draft)))
        )
        ->step(
            Step::make(S::Register)
                ->title('Complete registration')
                ->from(St::Approved)
                ->gate(FeesCleared::class)
                ->capability(ProgrammesPermission::Register)
                ->outcome(Outcome::make(O::Registered)->title('Registered')->transition(Transition::to(St::Registered)))
        )
        ->step(
            Step::make(S::Change)
                ->title('Change courses')
                ->from(St::Registered)
                ->gate(InAddDrop::class)
                ->capability(ProgrammesPermission::Register)
                ->outcome(Outcome::make(O::Reopened)->title('Reopened for a change of courses')->requiresReason()->transition(Transition::to(St::Draft)))
        )
        ->step(
            Step::make(S::Withdraw)
                ->title('Withdraw the registration')
                ->from(St::Draft, St::Submitted, St::Approved, St::Registered)
                ->capability(ProgrammesPermission::Register)
                ->outcome(Outcome::make(O::Withdrawn)->title('Withdrawn')->requiresReason()->transition(Transition::to(St::Withdrawn)))
        )
    );
