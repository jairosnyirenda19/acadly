<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Documents\DocumentOwner;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Domain\Assessment;

/** An assessment owns its marked papers; access is checked at its class. */
final class AssessmentOwner implements DocumentOwner
{
    public function scopeOf(string $ownerId): ?ScopeRef
    {
        $a = Ids::isValid($ownerId) ? Assessment::query()->find($ownerId) : null;

        return $a instanceof Assessment ? ScopeRef::of(ScopeType::ClassGroup, $a->class_id) : null;
    }

    public function labelOf(string $ownerId): string
    {
        $a = Ids::isValid($ownerId) ? Assessment::query()->find($ownerId) : null;

        return $a instanceof Assessment ? $a->title : 'An assessment';
    }
}
