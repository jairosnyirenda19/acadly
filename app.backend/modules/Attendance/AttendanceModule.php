<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance;

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;

/**
 * Registers, attendance events and corrections. Stage 0 holds the manifest
 * only, so the layer checks have a real optional module to guard.
 */
final class AttendanceModule implements Module
{
    public function key(): string
    {
        return 'attendance';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class];
    }

    public function permissions(): array
    {
        return AttendancePermission::cases();
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
}
