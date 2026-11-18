<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Domain\SupportPlan;
use Acadly\Modules\Support\Journeys\Plan\HasAGoal;
use Acadly\Modules\Support\Journeys\Plan\OutcomeId as O;
use Acadly\Modules\Support\Journeys\Plan\State as St;
use Acadly\Modules\Support\Journeys\Plan\StepId as S;

/*
 * A support plan (D-093): a concern is raised, the plan is agreed once it
 * has a goal, it is reviewed, and it closes with a named outcome.
 */
return JourneyBlueprint::make('support.plan@2026.1')
    ->title('Support plan')
    ->subject(SupportPlan::class)
    ->states(St::class, initial: St::Raised, terminal: [St::Closed])
    ->stage('plan', 'Plan', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Agree)
                ->title('Agree the plan')
                ->from(St::Raised)
                ->gate(HasAGoal::class)
                ->capability(SupportPermission::Lead)
                ->outcome(Outcome::make(O::Agreed)->title('Plan agreed')->transition(Transition::to(St::Reviewing)))
        )
        ->step(
            Step::make(S::Close)
                ->title('Close the plan')
                ->from(St::Raised, St::Reviewing)
                ->capability(SupportPermission::Lead)
                ->outcome(Outcome::make(O::GoalsMet)->title('Goals met')->transition(Transition::to(St::Closed)))
                ->outcome(Outcome::make(O::PartlyMet)->title('Partly met')->transition(Transition::to(St::Closed)))
                ->outcome(Outcome::make(O::Referred)->title('Referred on')->requiresReason()->transition(Transition::to(St::Closed)))
                ->outcome(Outcome::make(O::Left)->title('Left the school')->requiresReason()->transition(Transition::to(St::Closed)))
        )
    );
