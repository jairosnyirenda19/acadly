<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Domain\SupportPlan;
use Acadly\Modules\Support\Journeys\Plan\State;

/** The Support part of the learner record: an open plan, for those who may see support. */
final class SupportRecordPart implements LearnerRecordPart
{
    public function __construct(private readonly SupportReach $reach) {}

    public function key(): string
    {
        return 'support';
    }

    public function title(): string
    {
        return 'Support';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $plan = $this->open($viewer, $learnerId);

        return $plan === null ? [] : [['label' => 'Support plan', 'value' => State::from($plan->state)->label(), 'note' => $plan->title.'. Next review '.$plan->next_review_on->format('j M').'.', 'tone' => 'plain']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        $plan = $this->open($viewer, $learnerId);

        return $plan !== null && $plan->next_review_on->toDateString() <= now()->toDateString() ? 1 : 0;
    }

    private function open(User $viewer, string $learnerId): ?SupportPlan
    {
        if (! $this->reach->may($viewer, SupportPermission::View, $learnerId) && ! $this->reach->may($viewer, SupportPermission::Lead, $learnerId)) {
            return null;
        }

        foreach (SupportPlan::query()->where('learner_id', $learnerId)->where('state', '!=', State::Closed->value)->orderByDesc('created_at')->get() as $plan) {
            if ($this->reach->seesPlan($viewer, $plan)) {
                return $plan;
            }
        }

        return null;
    }
}
