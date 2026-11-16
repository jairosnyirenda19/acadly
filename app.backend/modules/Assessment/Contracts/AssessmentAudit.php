<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/**
 * What the assessment module writes to the audit trail. Submitting, locking
 * and returning marks are journey steps: the journey engine writes their lines.
 */
enum AssessmentAudit: string implements AuditAction
{
    case ScaleSaved = 'assessment.scale_saved';
    case LevelScaleSet = 'assessment.level_scale_set';
    case PlanSaved = 'assessment.plan_saved';
    case AssessmentSet = 'assessment.set';
    case MarksSaved = 'assessment.marks_saved';
    case MarkCorrected = 'assessment.mark_corrected';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::ScaleSaved => 'Added or changed a grading scale.',
            self::LevelScaleSet => 'Chose the grading scale of a level.',
            self::PlanSaved => 'Saved a version of a grading plan.',
            self::AssessmentSet => 'Set an assessment for a class and subject.',
            self::MarksSaved => 'Entered or changed marks of an assessment.',
            self::MarkCorrected => 'Corrected a locked mark.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::MarkCorrected;
    }
}
