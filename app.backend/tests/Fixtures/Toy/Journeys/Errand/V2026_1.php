<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Tests\Fixtures\Toy\Contracts\ErrandCompleted;
use Tests\Fixtures\Toy\Contracts\ToyPermission;
use Tests\Fixtures\Toy\Journeys\Errand\Limit;
use Tests\Fixtures\Toy\Journeys\Errand\Milestone;
use Tests\Fixtures\Toy\Journeys\Errand\OutcomeId as O;
use Tests\Fixtures\Toy\Journeys\Errand\RecordDecision;
use Tests\Fixtures\Toy\Journeys\Errand\State as St;
use Tests\Fixtures\Toy\Journeys\Errand\StepId as S;
use Tests\Fixtures\Toy\Journeys\Errand\TellRequester;
use Tests\Fixtures\Toy\Journeys\Errand\TitleWithin;
use Tests\Fixtures\Toy\ToyErrand;

return JourneyBlueprint::make('toy.errand@2026.1')
    ->title('Errand')
    ->subject(ToyErrand::class)
    ->states(St::class, initial: St::Draft, terminal: [St::Done])
    ->milestone(Milestone::Decided, St::Approved, St::Declined)

    ->stage('request', 'Request', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Submit)
                ->title('Submit the errand')
                ->from(St::Draft)
                ->gate(TitleWithin::class, new Limit(40))
                ->capability(ToyPermission::Submit)
                ->outcome(Outcome::make(O::Submitted)->title('Submit')->transition(Transition::to(St::Submitted)))
        )
        ->step(
            Step::make(S::Reopen)
                ->title('Reopen the errand')
                ->from(St::Declined)
                ->capability(ToyPermission::Submit)
                ->outcome(Outcome::make(O::Reopened)->title('Reopen')->transition(Transition::to(St::Draft)))
        )
    )

    ->stage('decision', 'Decision', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Decide)
                ->title('Decide on the errand')
                ->from(St::Submitted)
                ->capability(ToyPermission::Decide)
                ->fourEyes(S::Submit)
                ->outcome(
                    Outcome::make(O::Approved)->title('Approve')
                        ->effect(RecordDecision::class)
                        ->effect(TellRequester::class)
                        ->transition(Transition::to(St::Approved))
                )
                ->outcome(
                    Outcome::make(O::Declined)->title('Decline')
                        ->requiresReason()
                        ->effect(RecordDecision::class)
                        ->transition(Transition::to(St::Declined))
                )
        )
    )

    ->stage('completion', 'Completion', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Complete)
                ->title('Complete the errand')
                ->from(St::Approved)
                ->capability(ToyPermission::Complete)
                ->outcome(
                    Outcome::make(O::Done)->title('Mark as done')
                        ->transition(Transition::to(St::Done)->emits(ErrandCompleted::class))
                )
        )
    );
