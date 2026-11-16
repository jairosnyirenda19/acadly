<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\Assessment;
use Acadly\Modules\Assessment\Journeys\Marks\EveryLearnerMarked;
use Acadly\Modules\Assessment\Journeys\Marks\OutcomeId as O;
use Acadly\Modules\Assessment\Journeys\Marks\State as St;
use Acadly\Modules\Assessment\Journeys\Marks\StepId as S;
use Acadly\Modules\Assessment\Notifications\MarksReturned;
use Acadly\Modules\Assessment\Notifications\MarksSubmitted;

return JourneyBlueprint::make('assessment.marks@2026.1')
    ->title('Marks of an assessment')
    ->subject(Assessment::class)
    ->states(St::class, initial: St::Marking, terminal: [St::Locked])
    ->stage('marking', 'Marking', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Submit)
                ->title('Submit the marks')
                ->from(St::Marking, St::Returned)
                ->gate(EveryLearnerMarked::class)
                ->capability(AssessmentPermission::Enter)
                ->outcome(Outcome::make(O::Submitted)->title('Submit')->notify(MarksSubmitted::class)->transition(Transition::to(St::Submitted)))
        )
    )
    ->stage('check', 'Check', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Lock)
                ->title('Lock the marks')
                ->from(St::Submitted)
                ->capability(AssessmentPermission::Lock)
                ->fourEyes(S::Submit)
                ->outcome(Outcome::make(O::Locked)->title('Lock')->transition(Transition::to(St::Locked)))
        )
        ->step(
            Step::make(S::Return)
                ->title('Return the marks to the teacher')
                ->from(St::Submitted)
                ->capability(AssessmentPermission::Lock)
                ->outcome(Outcome::make(O::Returned)->title('Return')->requiresReason()->notify(MarksReturned::class)->transition(Transition::to(St::Returned)))
        )
    );
