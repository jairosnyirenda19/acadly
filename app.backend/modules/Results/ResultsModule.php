<?php

declare(strict_types=1);

namespace Acadly\Modules\Results;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Programmes\ProgrammesModule;
use Acadly\Modules\Results\Contracts\ResultsAudit;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Notifications\ResultsReleased;

/**
 * Results (D-105): a college's grading scale, each course's result sheet
 * from exam marks to release (continuous assessment from Classroom, the
 * attendance bar from Lectures), moderation, the examiners' board, GPAs,
 * the results slip and the supplementary sitting.
 */
final class ResultsModule implements Module
{
    public function key(): string
    {
        return 'results';
    }

    public function name(): string
    {
        return 'Results';
    }

    public function summary(): string
    {
        return 'Grading scale, exam marks, moderation, the examiners\' board, GPAs, release, the results slip and supplementary exams.';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class, EnrolmentCore::class, ProgrammesModule::class];
    }

    public function permissions(): array
    {
        return ResultsPermission::cases();
    }

    public function auditActions(): array
    {
        return ResultsAudit::cases();
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
        return ['results.sheet'];
    }

    public function topics(): array
    {
        return [ResultsReleased::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
