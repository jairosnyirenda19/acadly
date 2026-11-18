<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Conduct\Contracts\ConductPermission;

/** The Conduct part of the learner record: why to watch the learner, if there is a reason. */
final class ConductRecordPart implements LearnerRecordPart
{
    public function __construct(
        private readonly ConductReach $reach,
        private readonly ConductViews $views,
    ) {}

    public function key(): string
    {
        return 'conduct';
    }

    public function title(): string
    {
        return 'Conduct';
    }

    public function band(User $viewer, string $learnerId): array
    {
        if (! $this->reach->may($viewer, ConductPermission::View, $learnerId)) {
            return [];
        }

        $why = $this->views->watch([$learnerId])[$learnerId] ?? [];

        return $why === [] ? [] : [['label' => 'Conduct', 'value' => '!', 'note' => implode(' ', $why), 'tone' => 'attention']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return $this->reach->may($viewer, ConductPermission::View, $learnerId) && ($this->views->watch([$learnerId])[$learnerId] ?? []) !== [] ? 1 : 0;
    }
}
