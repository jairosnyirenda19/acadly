<?php

declare(strict_types=1);

namespace Acadly\Core\Academics;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/**
 * Academic years, their terms, and the classes of each level at each campus,
 * shaped by each division's profile (08, stage 2). Subjects and teaching
 * assignments join this domain with the screens that use them.
 */
final class AcademicsCore implements Module
{
    public function key(): string
    {
        return 'academics';
    }

    public function name(): string
    {
        return 'Academics';
    }

    public function summary(): string
    {
        return 'Years, terms, levels, classes, subjects and who teaches them.';
    }

    public function tier(): Tier
    {
        return Tier::Core;
    }

    public function requires(): array
    {
        return [InstitutionCore::class];
    }

    public function permissions(): array
    {
        return AcademicsPermission::cases();
    }

    public function auditActions(): array
    {
        return AcademicsAudit::cases();
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
        return [];
    }
}
