<?php

declare(strict_types=1);

namespace Acadly\Modules\News;

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\News\Contracts\NewsAudit;
use Acadly\Modules\News\Contracts\NewsPermission;

/**
 * News (D-101): a division posts news to everyone, a faculty, a department,
 * a programme, a year of study, or staff only. Students read it in the
 * student portal; staff on their dashboard. A shared part: any division may
 * use it once its profile lists it.
 */
final class NewsModule implements Module
{
    public function key(): string
    {
        return 'news';
    }

    public function name(): string
    {
        return 'News';
    }

    public function summary(): string
    {
        return 'News for students and staff, to the audiences the school chooses.';
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
        return NewsPermission::cases();
    }

    public function auditActions(): array
    {
        return NewsAudit::cases();
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
