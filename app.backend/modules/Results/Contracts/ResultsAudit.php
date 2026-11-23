<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What Results writes to the audit trail (D-105). */
enum ResultsAudit: string implements AuditAction
{
    case ScaleSaved = 'results.scale_saved';
    case SettingsSaved = 'results.settings_saved';
    case MarksEntered = 'results.marks_entered';
    case MarkModerated = 'results.mark_moderated';
    case BarLifted = 'results.bar_lifted';
    case Deferred = 'results.deferred';
    case Released = 'results.released';
    case Corrected = 'results.corrected';
    case SupplementaryPrepared = 'results.supplementary_prepared';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::ScaleSaved => 'Saved a grading scale.',
            self::SettingsSaved => 'Changed the results settings.',
            self::MarksEntered => 'Entered exam marks.',
            self::MarkModerated => 'Changed a mark in moderation.',
            self::BarLifted => 'Lifted an attendance bar.',
            self::Deferred => 'Accepted an absence from an exam: the exam is deferred.',
            self::Released => 'Released results to students.',
            self::Corrected => 'Changed a released result.',
            self::SupplementaryPrepared => 'Prepared the supplementary sitting.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::MarkModerated, self::BarLifted, self::Deferred, self::Corrected], true);
    }
}
