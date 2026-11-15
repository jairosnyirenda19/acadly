<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Documents\DocumentOwner;

/** A learner's documents live where the learner is placed now. */
final class LearnerOwner implements DocumentOwner
{
    public function __construct(private readonly LearnerPlaces $places) {}

    public function scopeOf(string $ownerId): ?ScopeRef
    {
        return $this->places->scopeOf($ownerId);
    }

    public function labelOf(string $ownerId): string
    {
        $id = Learner::query()->whereKey($ownerId)->value('acadly_id');

        return is_string($id) ? 'Learner '.$id : 'Learner';
    }
}
