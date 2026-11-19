<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Review;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Assessment\Application\ProgressReviews;
use Acadly\Modules\Assessment\Domain\ProgressReview;

/** A review goes to be checked only with a step in every learning area and the note for the family. */
final class ReviewIsComplete implements Gate
{
    public function __construct(private readonly ProgressReviews $reviews) {}

    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $review = $context->subject;

        if (! $review instanceof ProgressReview) {
            return GateResult::blocked('This is not a progress review.', 'Open the review from Progress reviews.');
        }

        $missing = $this->reviews->areasWithoutStep($review);

        if ($missing > 0) {
            return GateResult::blocked(
                $missing === 1 ? '1 learning area has no step.' : "{$missing} learning areas have no step.",
                'Choose the step reached in each learning area.',
            );
        }

        if ($review->family_note === null || trim($review->family_note) === '') {
            return GateResult::blocked('The note for the family is empty.', 'Write what the family must know.');
        }

        return GateResult::ok();
    }
}
