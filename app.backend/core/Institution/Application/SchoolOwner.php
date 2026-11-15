<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Documents\DocumentOwner;

/** Documents the school owns live at the institution scope. */
final class SchoolOwner implements DocumentOwner
{
    public function scopeOf(string $ownerId): ?ScopeRef
    {
        return School::query()->whereKey($ownerId)->exists() ? ScopeRef::institution() : null;
    }

    public function labelOf(string $ownerId): string
    {
        return School::query()->find($ownerId)->name ?? 'School';
    }
}
