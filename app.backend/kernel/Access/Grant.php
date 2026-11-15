<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/** One permission an account holds at one scope, through an active role assignment. */
final readonly class Grant
{
    public function __construct(
        public string $permission,
        public ScopeRef $scope,
    ) {}
}
