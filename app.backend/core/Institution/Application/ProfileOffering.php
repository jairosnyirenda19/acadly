<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleOffering;

/**
 * Whether a division's profile offers a module (D-099): the parts its Profile
 * Blueprint lists. At a division, or anywhere inside one, a module the
 * profile does not list is not offered: it is off there, whatever the switch
 * says. At the whole school or a campus it is offered when the school runs it.
 */
final class ProfileOffering implements ModuleOffering
{
    /** @var array<string, bool> module and division => offered, for this request */
    private array $known = [];

    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly ScopeHierarchy $hierarchy,
    ) {}

    public function offers(Module $module, ScopeRef $at): bool
    {
        $division = $this->divisionOf($at);

        if ($division === null) {
            return true;
        }

        return $this->known[$module->key().'|'.$division] ??= ($this->school->division($division)?->profile->offersModule($module->key()) ?? true);
    }

    public function requires(Module $module, ScopeRef $at): bool
    {
        return false;
    }

    private function divisionOf(ScopeRef $at): ?string
    {
        if ($at->type === ScopeType::Division) {
            return $at->id;
        }

        if (! in_array($at->type, [ScopeType::Offering, ScopeType::ClassGroup], true)) {
            return null;
        }

        foreach ($this->hierarchy->ancestors($at) as $a) {
            if ($a->type === ScopeType::Division) {
                return $a->id;
            }
        }

        return null;
    }
}
