<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\SafeRelease\Contracts\SafeReleaseAudit;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Acadly\Modules\SafeRelease\Notifications\ChildReleased;
use Acadly\Modules\SafeRelease\Notifications\ReleaseRefused;

/**
 * Safe pick-up (D-094): a child is released only to someone the family
 * named, or to a one-day collector the family adds with a code. Every
 * release and every refusal is recorded and the family is told. The school's
 * safeguarding lead agrees the rules before the module is switched on.
 */
final class SafeReleaseModule implements Module
{
    public function key(): string
    {
        return 'saferelease';
    }

    public function name(): string
    {
        return 'Safe pick-up';
    }

    public function summary(): string
    {
        return 'Children released only to people the family named, with every release and refusal recorded and the family told.';
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
        return SafeReleasePermission::cases();
    }

    public function auditActions(): array
    {
        return SafeReleaseAudit::cases();
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
        return ['saferelease.pickup'];
    }

    public function topics(): array
    {
        return [ChildReleased::class, ReleaseRefused::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
