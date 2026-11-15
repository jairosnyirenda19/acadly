<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Access\ScopeRef;

/** Until Profile Blueprints exist: every installed module is offered, none is required. */
final class OfferEverything implements ModuleOffering
{
    public function offers(Module $module, ScopeRef $at): bool
    {
        return true;
    }

    public function requires(Module $module, ScopeRef $at): bool
    {
        return false;
    }
}
