<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\AwardList;
use Acadly\Modules\Results\Journeys\Award\OutcomeId as O;
use Acadly\Modules\Results\Journeys\Award\State as St;
use Acadly\Modules\Results\Journeys\Award\StepId as S;

/*
 * A programme's graduation list (D-106): worked out from the released
 * results and the rules, approved by the examiners' board, confirmed by the
 * academic board, and conferred on the graduation day, when each student's
 * programme ends as completed.
 */
return JourneyBlueprint::make('results.award@2026.1')
    ->title('Graduation list')
    ->subject(AwardList::class)
    ->states(St::class, initial: St::WorkedOut, terminal: [St::Conferred])
    ->stage('award', 'Award', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Approve)
                ->title('Approve the list')
                ->from(St::WorkedOut)
                ->capability(ResultsPermission::Board)
                ->outcome(Outcome::make(O::Approved)->title('Approved by the board')->transition(Transition::to(St::Approved)))
        )
        ->step(
            Step::make(S::Confirm)
                ->title('Confirm the list')
                ->from(St::Approved)
                ->capability(ResultsPermission::Award)
                ->outcome(Outcome::make(O::Confirmed)->title('Confirmed by the academic board')->transition(Transition::to(St::Confirmed)))
                ->outcome(Outcome::make(O::Returned)->title('Returned to the examiners\' board')->requiresReason()->transition(Transition::to(St::WorkedOut)))
        )
        ->step(
            Step::make(S::Confer)
                ->title('Confer the awards')
                ->from(St::Confirmed)
                ->capability(ResultsPermission::Release)
                ->outcome(Outcome::make(O::Conferred)->title('Conferred')->transition(Transition::to(St::Conferred)))
        )
    );
