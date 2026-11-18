<?php

declare(strict_types=1);

namespace Acadly\Modules\Support;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Support\Contracts\SupportAudit;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Acadly\Modules\Support\Notifications\PlanNotice;

/**
 * Support and trends (D-093): learners to watch from the signals of the
 * modules that are on, trends by term, and support plans.
 */
final class SupportModule implements Module
{
    public function key(): string
    {
        return 'support';
    }

    public function name(): string
    {
        return 'Support';
    }

    public function summary(): string
    {
        return 'Learners to watch from attendance, results and conduct, trends by term, and support plans with goals and reviews.';
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
        return SupportPermission::cases();
    }

    public function auditActions(): array
    {
        return SupportAudit::cases();
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
        return ['support.plan'];
    }

    public function topics(): array
    {
        return [PlanNotice::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
