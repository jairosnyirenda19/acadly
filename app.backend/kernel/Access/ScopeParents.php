<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/**
 * Knows the places of some scope types: what directly contains each, what
 * each is called, and which are in force. The domains that own those places
 * register one (tag `acadly.scope-parents`); the kernel composes them into the
 * scope tree and names no domain.
 */
interface ScopeParents
{
    /** @return list<ScopeType> the scope types this one answers for */
    public function types(): array;

    /**
     * The scopes that directly contain $scope, or null when no such place is
     * in force. The institution is implied and need not be listed.
     *
     * @return list<ScopeRef>|null
     */
    public function parents(ScopeRef $scope): ?array;

    /** What people call the place, or null when it is unknown. */
    public function label(ScopeRef $scope): ?string;

    /**
     * The places in force a role can be given at, in the order people expect.
     *
     * @return list<array{scope: ScopeRef, label: string}>
     */
    public function places(): array;
}
