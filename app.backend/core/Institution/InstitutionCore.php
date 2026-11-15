<?php

declare(strict_types=1);

namespace Acadly\Core\Institution;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionDocument;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/**
 * The school, its divisions and its campuses
 * (docs/architecture/03-deployment-campus-access.md §1): the school's details
 * and logo, the levels it runs (each pinned to a Profile Blueprint), its
 * campuses, and which level is taught where.
 */
final class InstitutionCore implements Module
{
    public function key(): string
    {
        return 'institution';
    }

    public function name(): string
    {
        return 'Institution';
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
        return InstitutionPermission::cases();
    }

    public function auditActions(): array
    {
        return InstitutionAudit::cases();
    }

    public function events(): array
    {
        return [];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return [];
    }

    public function topics(): array
    {
        return [];
    }

    public function documentKinds(): array
    {
        return InstitutionDocument::cases();
    }
}
