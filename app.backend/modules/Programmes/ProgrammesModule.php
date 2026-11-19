<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;

/**
 * Programmes (D-097): a college's faculties and departments, its programmes
 * and their curriculum versions, the course catalogue, and the offerings of
 * each semester with their lecturers. Semester registration builds on it.
 */
final class ProgrammesModule implements Module
{
    public function key(): string
    {
        return 'programmes';
    }

    public function name(): string
    {
        return 'Programmes';
    }

    public function summary(): string
    {
        return 'Faculties, departments, programmes with their curricula, the course catalogue and each semester\'s offerings.';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class, EnrolmentCore::class];
    }

    public function permissions(): array
    {
        return ProgrammesPermission::cases();
    }

    public function auditActions(): array
    {
        return ProgrammesAudit::cases();
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
        return ['programmes.registration'];
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
