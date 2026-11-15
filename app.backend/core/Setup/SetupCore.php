<?php

declare(strict_types=1);

namespace Acadly\Core\Setup;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/**
 * School setup, for the first administrator (the approved setup screens): it
 * reads where each step stands from Institution and Academics, keeps the
 * staff list, and opens the school. It owns no tables.
 */
final class SetupCore implements Module
{
    public function key(): string
    {
        return 'setup';
    }

    public function name(): string
    {
        return 'School setup';
    }

    public function tier(): Tier
    {
        return Tier::Core;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class];
    }

    public function permissions(): array
    {
        return [];
    }

    public function auditActions(): array
    {
        return [];
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
