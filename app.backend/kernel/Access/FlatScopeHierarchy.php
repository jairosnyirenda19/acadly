<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/** Every scope sits directly inside the institution. For tests of the kernel alone. */
final class FlatScopeHierarchy implements ScopeHierarchy
{
    public function ancestors(ScopeRef $scope): array
    {
        return $scope->type === ScopeType::Institution ? [] : [ScopeRef::institution()];
    }

    public function exists(ScopeRef $scope): bool
    {
        return true;
    }

    public function label(ScopeRef $scope): string
    {
        return $scope->type === ScopeType::Institution ? ScopeTree::WHOLE_SCHOOL : ucfirst($scope->type->value);
    }

    public function places(): array
    {
        return [['scope' => ScopeRef::institution(), 'label' => ScopeTree::WHOLE_SCHOOL]];
    }
}
