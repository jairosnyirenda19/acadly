<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fees in the parent portal (D-108): the school's setting, a school-wide one,
 * and the record of each fee document a parent downloads from the portal.
 */
final class FamilyFeeActions
{
    public const int HOW_TO_PAY_MAX = 600;

    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Whether the confirmed parents of each child see the child's fees, and the words that say how to pay. */
    public function saveSettings(User $by, bool $showToFamilies, string $howToPay): void
    {
        $this->access->check($by, FeesPermission::Manage);
        $howToPay = trim(str_replace("\r\n", "\n", $howToPay));

        if (mb_strlen($howToPay) > self::HOW_TO_PAY_MAX) {
            throw ValidationException::withMessages(['how_to_pay' => ['Up to '.self::HOW_TO_PAY_MAX.' characters.']]);
        }

        if (preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|org|net|mw|io|app)\b)~i', $howToPay) === 1) {
            throw ValidationException::withMessages(['how_to_pay' => ['Links are not allowed. Write the bank, account and mobile money numbers as words and figures.']]);
        }

        DB::transaction(function () use ($by, $showToFamilies, $howToPay): void {
            DB::table('fees_portal_settings')->updateOrInsert(['id' => 1], ['show_to_families' => $showToFamilies, 'how_to_pay' => $howToPay === '' ? null : $howToPay,
                'updated_by' => $by->id, 'updated_at' => $this->clock->now()]);
            $this->audit->record(FeesAudit::PortalSettingsSaved, actor: $this->audit->actorFor($by), meta: ['show_to_families' => $showToFamilies, 'how_to_pay_length' => mb_strlen($howToPay)]);
        });
    }

    /** A parent downloaded a receipt or the fee statement of their child. */
    public function downloaded(User $parent, string $learnerId, string $document, ?string $reference): void
    {
        $this->audit->record(FeesAudit::FamilyDownloaded, actor: $this->audit->actorFor($parent), aboutPersonId: $learnerId,
            meta: ['document' => $document, 'reference' => $reference ?? $this->clock->today(), 'learner_id' => $learnerId]);
    }
}
