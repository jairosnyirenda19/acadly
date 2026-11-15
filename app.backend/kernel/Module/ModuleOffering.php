<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Access\ScopeRef;

/**
 * Whether the profile of a scope offers a module: listed as required or
 * allowed in the division's Profile Blueprint (docs/architecture/02-blueprints.md).
 *
 * A socket. The blueprint engine binds the real answer; until it exists,
 * OfferEverything answers yes.
 */
interface ModuleOffering
{
    public function offers(Module $module, ScopeRef $at): bool;

    /** A required module cannot be switched off at this scope. */
    public function requires(Module $module, ScopeRef $at): bool;
}
