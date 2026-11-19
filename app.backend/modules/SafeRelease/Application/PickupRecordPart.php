<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\SafeRelease\Domain\Pickup;
use Acadly\Modules\SafeRelease\Journeys\Pickup\State;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Safe pick-up's part of the learner record: today's pick-up and who may collect. */
final class PickupRecordPart implements LearnerRecordPart
{
    public function __construct(
        private readonly PickupReach $reach,
        private readonly Collectors $collectors,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'saferelease';
    }

    public function title(): string
    {
        return 'Pick-up';
    }

    public function band(User $viewer, string $learnerId): array
    {
        try {
            $this->reach->learner($viewer, $learnerId);
        } catch (NotFoundHttpException) {
            return [];
        }

        $today = $this->clock->today();
        $done = Pickup::query()->where('learner_id', $learnerId)->where('day', $today)->where('state', State::Released->value)->first();
        $refused = $this->refusedToday($learnerId);

        return [
            $done instanceof Pickup
                ? ['label' => 'Today', 'value' => 'Collected', 'note' => ($done->released_at ?? $done->created_at)->format('H:i').' by '.$done->collector_name.'.', 'tone' => 'plain']
                : ['label' => 'Today', 'value' => 'Not collected', 'note' => $refused > 0 ? 'Someone not on the list was refused today.' : 'Not yet collected.', 'tone' => $refused > 0 ? 'attention' : 'plain'],
            ['label' => 'May collect', 'value' => (string) count($this->collectors->mayCollect($learnerId, $today)), 'note' => 'People the family named.', 'tone' => 'plain'],
        ];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        try {
            $this->reach->learner($viewer, $learnerId);
        } catch (NotFoundHttpException) {
            return 0;
        }

        return $this->refusedToday($learnerId) > 0 ? 1 : 0;
    }

    private function refusedToday(string $learnerId): int
    {
        return Pickup::query()->where('learner_id', $learnerId)->where('day', $this->clock->today())->where('state', State::Disputed->value)->count();
    }
}
