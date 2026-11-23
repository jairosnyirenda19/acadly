<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\Sheet;
use Acadly\Modules\Results\Journeys\Sheet\MarksComplete;
use Acadly\Modules\Results\Journeys\Sheet\OutcomeId as O;
use Acadly\Modules\Results\Journeys\Sheet\State as St;
use Acadly\Modules\Results\Journeys\Sheet\StepId as S;

/*
 * A course's result sheet (D-105): the lecturer enters the exam marks, the
 * head of department moderates, the examiners' board approves, and the
 * registrar releases. Moderation and the board may return a sheet, with a
 * reason. A change after release is a board decision, recorded as a new row.
 */
return JourneyBlueprint::make('results.sheet@2026.1')
    ->title('Course results')
    ->subject(Sheet::class)
    ->states(St::class, initial: St::Entered, terminal: [St::Released])
    ->stage('results', 'Results', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Submit)
                ->title('Send for moderation')
                ->from(St::Entered)
                ->gate(MarksComplete::class)
                ->capability(ResultsPermission::Enter)
                ->outcome(Outcome::make(O::Submitted)->title('Sent for moderation')->transition(Transition::to(St::Submitted)))
        )
        ->step(
            Step::make(S::Moderate)
                ->title('Moderate the marks')
                ->from(St::Submitted)
                ->capability(ResultsPermission::Moderate)
                ->outcome(Outcome::make(O::Moderated)->title('Moderated')->transition(Transition::to(St::Moderated)))
                ->outcome(Outcome::make(O::Returned)->title('Returned to the lecturer')->requiresReason()->transition(Transition::to(St::Entered)))
        )
        ->step(
            Step::make(S::Approve)
                ->title('Approve at the board')
                ->from(St::Moderated)
                ->capability(ResultsPermission::Board)
                ->outcome(Outcome::make(O::Approved)->title('Approved by the board')->transition(Transition::to(St::Approved)))
                ->outcome(Outcome::make(O::Returned)->title('Returned for moderation')->requiresReason()->transition(Transition::to(St::Submitted)))
        )
        ->step(
            Step::make(S::Release)
                ->title('Release to students')
                ->from(St::Approved)
                ->capability(ResultsPermission::Release)
                ->outcome(Outcome::make(O::Released)->title('Released')->transition(Transition::to(St::Released)))
        )
    );
