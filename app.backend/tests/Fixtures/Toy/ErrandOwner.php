<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Documents\DocumentOwner;

/** Errands live at the institution. */
final class ErrandOwner implements DocumentOwner
{
    public function scopeOf(string $ownerId): ?ScopeRef
    {
        return ToyErrand::query()->whereKey($ownerId)->exists() ? ScopeRef::institution() : null;
    }

    public function labelOf(string $ownerId): string
    {
        return ToyErrand::query()->find($ownerId)->title ?? 'Errand';
    }
}
