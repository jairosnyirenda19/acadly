<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\ProgressReview;
use Acadly\Modules\Assessment\Journeys\Review\OutcomeId as O;
use Acadly\Modules\Assessment\Journeys\Review\ReviewIsComplete;
use Acadly\Modules\Assessment\Journeys\Review\State as St;
use Acadly\Modules\Assessment\Journeys\Review\StepId as S;

/*
 * A child's progress review for a term (D-094): the caregiver drafts it, the
 * head of nursery checks it (never one they submitted) or returns it with a
 * reason, and a checked review is shared with the family.
 */
return JourneyBlueprint::make('assessment.progress_review@2026.1')
    ->title('Progress review')
    ->subject(ProgressReview::class)
    ->states(St::class, initial: St::Draft, terminal: [St::Shared])
    ->stage('draft', 'Draft', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Submit)
                ->title('Send the review to be checked')
                ->from(St::Draft)
                ->gate(ReviewIsComplete::class)
                ->capability(AssessmentPermission::Observe)
                ->outcome(Outcome::make(O::Submitted)->title('Send to be checked')->transition(Transition::to(St::Ready)))
        )
    )
    ->stage('check', 'Check', fn (Stage $stage): Stage => $stage
        ->step(
            Step::make(S::Check)
                ->title('Check the review')
                ->from(St::Ready)
                ->capability(AssessmentPermission::Review)
                ->fourEyes(S::Submit)
                ->outcome(Outcome::make(O::Checked)->title('Checked')->transition(Transition::to(St::Checked)))
        )
        ->step(
            Step::make(S::Return)
                ->title('Return the review to the caregiver')
                ->from(St::Ready, St::Checked)
                ->capability(AssessmentPermission::Review)
                ->outcome(Outcome::make(O::Returned)->title('Return')->requiresReason()->transition(Transition::to(St::Draft)))
        )
        ->step(
            Step::make(S::Share)
                ->title('Share the review with the family')
                ->from(St::Checked)
                ->capability(AssessmentPermission::Review)
                ->outcome(Outcome::make(O::Shared)->title('Share')->transition(Transition::to(St::Shared)))
        )
    );
