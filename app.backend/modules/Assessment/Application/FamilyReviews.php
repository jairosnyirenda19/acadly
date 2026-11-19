<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Assessment\Domain\ProgressReview;
use Acadly\Modules\Assessment\Journeys\Review\State;

/**
 * The progress review's line on a child's card in the parent portal (D-094),
 * for a level that records observations. It stands where a report card stands
 * for a level that takes marks, so the portal shows the review instead.
 */
final class FamilyReviews implements FamilyLine
{
    /** A review is new for this many days after it was shared. */
    public const int NEW_DAYS = 14;

    public function __construct(
        private readonly Reach $reach,
        private readonly EnrolmentDirectory $directory,
        private readonly Observations $observations,
        private readonly Clock $clock,
        private readonly ModuleActivation $activation,
    ) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        $place = $this->directory->placeOf($learnerId);

        if ($place === null || $this->reach->takesMarks($place['division_id']) || ! $this->activation->isActive('assessment', ScopeRef::of(ScopeType::ClassGroup, $place['class_id']))) {
            return null;
        }

        $review = ProgressReview::query()->where('learner_id', $learnerId)->where('state', State::Shared->value)->orderByDesc('shared_at')->first();

        if (! $review instanceof ProgressReview || $review->shared_at === null) {
            return ['key' => 'review', 'word' => 'no_review', 'label' => null, 'tone' => 'plain'];
        }

        $new = $review->shared_at->toIso8601String() >= $this->clock->now()->subDays(self::NEW_DAYS)->toIso8601String();

        return ['key' => 'review', 'word' => $new ? 'new_review' : 'review', 'label' => $this->observations->periodName($review->period_id), 'tone' => $new ? 'attention' : 'plain'];
    }
}
