<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentDocument;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/**
 * Learners (08, stage 3, first release): one record for life, the school's
 * own numbers, the stay at the school, the class the learner is in, and who
 * to contact. Applications, transfers, leaving and promotion follow.
 */
final class EnrolmentCore implements Module
{
    public function key(): string
    {
        return 'enrolment';
    }

    public function name(): string
    {
        return 'Learners';
    }

    public function summary(): string
    {
        return 'Admissions, the learner record, contacts and imports.';
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
        return EnrolmentPermission::cases();
    }

    public function auditActions(): array
    {
        return EnrolmentAudit::cases();
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
        return ['enrolment.guardian_link'];
    }

    public function topics(): array
    {
        return [];
    }

    public function documentKinds(): array
    {
        return EnrolmentDocument::cases();
    }
}
