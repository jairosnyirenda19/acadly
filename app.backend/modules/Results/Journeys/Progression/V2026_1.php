<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\ProgressionList;
use Acadly\Modules\Results\Journeys\Progression\EveryStudentDecided;
use Acadly\Modules\Results\Journeys\Progression\OutcomeId as O;
use Acadly\Modules\Results\Journeys\Progression\State as St;
use Acadly\Modules\Results\Journeys\Progression\StepId as S;

/*
 * The end of a year for one programme and year of study (D-106): the
 * decisions are worked out from the released results and the rules, the
 * examiners' board decides (choosing for the students the rules leave to
 * it), and the registrar applies them through Programmes.
 */
return JourneyBlueprint::make('results.progression@2026.1')
    ->title('End-of-year progression')
    ->subject(ProgressionList::class)
    ->states(St::class, initial: St::WorkedOut, terminal: [St::Applied])
    ->stage('progression', 'Progression', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Decide)
                ->title('Decide these progressions')
                ->from(St::WorkedOut)
                ->gate(EveryStudentDecided::class)
                ->capability(ResultsPermission::Board)
                ->outcome(Outcome::make(O::Decided)->title('Decided by the board')->transition(Transition::to(St::Decided)))
        )
        ->step(
            Step::make(S::Reopen)
                ->title('Reopen the decisions')
                ->from(St::Decided)
                ->capability(ResultsPermission::Board)
                ->outcome(Outcome::make(O::Reopened)->title('Reopened')->requiresReason()->transition(Transition::to(St::WorkedOut)))
        )
        ->step(
            Step::make(S::Apply)
                ->title('Apply the decisions')
                ->from(St::Decided)
                ->capability(ResultsPermission::Release)
                ->outcome(Outcome::make(O::Applied)->title('Applied')->transition(Transition::to(St::Applied)))
        )
    );
