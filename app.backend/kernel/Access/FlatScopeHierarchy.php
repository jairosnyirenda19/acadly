<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/** Every scope sits directly inside the institution. */
final class FlatScopeHierarchy implements ScopeHierarchy
{
    public function ancestors(ScopeRef $scope): array
    {
        return $scope->type === ScopeType::Institution ? [] : [ScopeRef::institution()];
    }
}
