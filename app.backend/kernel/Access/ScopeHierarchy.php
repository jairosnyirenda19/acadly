<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/**
 * Which scopes contain a given scope. A role held at a containing scope also
 * applies inside it: a role held at the institution applies at every campus.
 *
 * The kernel ships a flat hierarchy. The Institution core domain binds the
 * real one (class → campus and division → institution) when it is built.
 */
interface ScopeHierarchy
{
    /**
     * The scopes that contain $scope, nearest first. The scope itself is not
     * included.
     *
     * @return list<ScopeRef>
     */
    public function ancestors(ScopeRef $scope): array;
}
