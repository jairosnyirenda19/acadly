<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/**
 * Which scopes contain a given scope. A role held at a containing scope also
 * applies inside it: a role held at the institution applies at every campus.
 */
interface ScopeHierarchy
{
    /**
     * The scopes that contain $scope, nearest first, ending with the
     * institution. The scope itself is not included.
     *
     * @return list<ScopeRef>
     */
    public function ancestors(ScopeRef $scope): array;

    /** Whether the place is in force: a role can be given there. */
    public function exists(ScopeRef $scope): bool;

    /** What people call the place. */
    public function label(ScopeRef $scope): string;

    /**
     * Every place in force, the whole school first.
     *
     * @return list<array{scope: ScopeRef, label: string}>
     */
    public function places(): array;
}
