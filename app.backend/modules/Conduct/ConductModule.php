<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Conduct\Contracts\ConductAudit;
use Acadly\Modules\Conduct\Contracts\ConductDocument;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Notifications\ConductRecorded;
use Acadly\Modules\Conduct\Notifications\OutcomeDecided;

/**
 * Conduct (D-092): incidents and commendations without points, serious
 * incidents followed up as a journey, and learners to watch by the
 * school's explainable rules.
 */
final class ConductModule implements Module
{
    public function key(): string
    {
        return 'conduct';
    }

    public function name(): string
    {
        return 'Conduct';
    }

    public function summary(): string
    {
        return 'Incidents and commendations, serious incidents followed up to a decision, and learners to watch.';
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
        return ConductPermission::cases();
    }

    public function auditActions(): array
    {
        return ConductAudit::cases();
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
        return ['conduct.serious_incident'];
    }

    public function topics(): array
    {
        return [ConductRecorded::class, OutcomeDecided::class];
    }

    public function documentKinds(): array
    {
        return ConductDocument::cases();
    }
}
