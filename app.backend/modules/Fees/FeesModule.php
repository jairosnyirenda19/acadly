<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Notifications\FeeReminder;
use Acadly\Modules\Fees\Notifications\PaymentReceived;

/**
 * Fees and receipts (D-091): what each term costs, instalments, payments
 * and receipts, discounts and bursaries, the school shop, and the fees
 * desk. Billing and receivables only: the school's accounts stay in its
 * accounting system, which takes the download.
 */
final class FeesModule implements Module
{
    public function key(): string
    {
        return 'fees';
    }

    public function name(): string
    {
        return 'Fees';
    }

    public function summary(): string
    {
        return 'Term fees by level, instalment plans, payments and receipts, discounts and bursaries, the school shop and the fees desk.';
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
        return FeesPermission::cases();
    }

    public function auditActions(): array
    {
        return FeesAudit::cases();
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
        return [PaymentReceived::class, FeeReminder::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
