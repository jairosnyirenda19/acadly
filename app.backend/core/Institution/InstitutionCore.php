<?php

declare(strict_types=1);

namespace Acadly\Core\Institution;

use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/**
 * Tenants, institutions, divisions and campuses
 * (docs/architecture/03-deployment-campus-access.md §1). Stage 0 holds the
 * manifest only; the domain arrives in stage 2.
 */
final class InstitutionCore implements Module
{
    public function key(): string
    {
        return 'institution';
    }

    public function tier(): Tier
    {
        return Tier::Core;
    }

    public function requires(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }
}
