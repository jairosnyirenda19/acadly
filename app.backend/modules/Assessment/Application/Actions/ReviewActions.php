<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Observations;
use Acadly\Modules\Assessment\Application\ProgressReviews;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\ObservationStep;
use Acadly\Modules\Assessment\Domain\ProgressReview;
use Acadly\Modules\Assessment\Journeys\Review\OutcomeId;
use Acadly\Modules\Assessment\Journeys\Review\State;
use Acadly\Modules\Assessment\Journeys\Review\StepId;
use Acadly\Modules\Assessment\Notifications\ReviewShared;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Progress reviews (D-094). The caregiver drafts; the head of nursery checks
 * or returns with a reason; a checked review is shared and the family is
 * told. Every change of state goes through the journey engine.
 */
final class ReviewActions
{
    public function __construct(
        private readonly ProgressReviews $reviews,
        private readonly Observations $observations,
        private readonly Access $access,
        private readonly Blueprints $blueprints,
        private readonly JourneyEngine $engine,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Saves the draft of a child's review for this term, and starts it when there is none.
     *
     * @param  array<string, string>  $steps  learning area id => step
     */
    public function save(User $by, string $learnerId, array $steps, ?string $familyNote, ?string $homeNote): ProgressReview
    {
        [$room, $period] = $this->reviews->placeOf($by, $learnerId);
        $at = $this->observations->roomScope($room['id']);
        $this->access->check($by, AssessmentPermission::Observe, $at);
        $areas = array_column($this->observations->areasOf($room), 'id');
        $reached = array_map(fn (ObservationStep $s): string => $s->value, ObservationStep::reached());

        foreach ($steps as $area => $step) {
            if (! in_array($area, $areas, true) || ! in_array($step, $reached, true)) {
                throw ValidationException::withMessages(['steps' => ['Choose Starting, Growing or Can do it for a learning area of this room.']]);
            }
        }

        $clean = fn (?string $v): ?string => $v !== null && trim($v) !== '' ? mb_substr(trim($v), 0, 2000) : null;

        return DB::transaction(function () use ($by, $learnerId, $room, $period, $at, $steps, $familyNote, $homeNote, $clean): ProgressReview {
            $review = ProgressReview::query()->where('learner_id', $learnerId)->where('period_id', $period['id'])->lockForUpdate()->first();

            if ($review instanceof ProgressReview && $review->state !== State::Draft->value) {
                throw ValidationException::withMessages(['review' => ['The review is no longer a draft. It must be returned before it changes.']]);
            }

            if (! $review instanceof ProgressReview) {
                $review = ProgressReview::query()->create([
                    'learner_id' => $learnerId, 'class_id' => $room['id'], 'division_id' => $room['division_id'], 'period_id' => $period['id'],
                    'state' => State::Draft->value, 'journey_ref' => (string) $this->blueprints->newest('assessment.progress_review'), 'drafted_by' => $by->id,
                ]);
            }

            $now = $this->clock->now();
            $had = $this->reviews->stepsOf([$review->id])[$review->id] ?? [];

            foreach ($steps as $area => $step) {
                if (($had[$area] ?? null) !== $step) {
                    DB::table('progress_review_steps')->insert(['id' => Ids::next(), 'review_id' => $review->id, 'subject_id' => $area, 'step' => $step, 'set_by' => $by->id, 'created_at' => $now]);
                }
            }

            $review->family_note = $clean($familyNote);
            $review->home_note = $clean($homeNote);
            $review->drafted_by = $by->id;
            $review->touch();
            $review->save();
            $this->audit->record(AssessmentAudit::ReviewSaved, subject: $review, actor: $this->audit->actorFor($by), at: $at, meta: ['learner' => $learnerId, 'steps' => count($steps)]);

            return $review;
        });
    }

    public function submit(User $by, string $reviewId): void
    {
        $review = $this->find($by, $reviewId);
        DB::transaction(fn () => $this->engine->perform($review, $by, StepId::Submit, OutcomeId::Submitted));
    }

    public function check(User $by, string $reviewId): void
    {
        $review = $this->find($by, $reviewId);
        DB::transaction(fn () => $this->engine->perform($review, $by, StepId::Check, OutcomeId::Checked));
    }

    public function return(User $by, string $reviewId, string $reason): void
    {
        $review = $this->find($by, $reviewId);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say what must change.']]);
        }

        DB::transaction(fn () => $this->engine->perform($review, $by, StepId::Return, OutcomeId::Returned, ['reason' => trim($reason)]));
    }

    /**
     * Shares checked reviews with their families. Reviews the person may not
     * share, or that are not checked, are left as they are.
     *
     * @param  list<string>  $ids
     * @return int how many were shared
     */
    public function share(User $by, array $ids): int
    {
        $shared = 0;

        foreach (ProgressReview::query()->whereIn('id', $ids)->where('state', State::Checked->value)->get() as $review) {
            if (! $this->access->allows($by, AssessmentPermission::Review, $review->journeyScope())) {
                continue;
            }

            DB::transaction(function () use ($by, $review): void {
                $this->engine->perform($review, $by, StepId::Share, OutcomeId::Shared);
                DB::table('progress_reviews')->where('id', $review->id)->update(['shared_at' => $this->clock->now()]);
                $period = $this->observations->periodName($review->period_id);
                $this->notifier->send(new ReviewShared($review->id, $review->learner_id, $review->class_id, $period), $by);
            });
            $shared++;
        }

        return $shared;
    }

    private function find(User $by, string $id): ProgressReview
    {
        $review = ProgressReview::query()->find($id);

        if (! $review instanceof ProgressReview) {
            throw new NotFoundHttpException;
        }

        $this->observations->room($by, $review->class_id);

        return $review;
    }
}
