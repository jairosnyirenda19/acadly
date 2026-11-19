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
    case PaperAdded = 'assessment.paper_added';
    case PaperChanged = 'assessment.paper_changed';
    case CommentWritten = 'assessment.comment_written';
    case ResultsPublished = 'assessment.results_published';
    case CardSettingsSaved = 'assessment.card_settings_saved';
    case Observed = 'assessment.observed';
    case ReviewSaved = 'assessment.review_saved';

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
            self::PaperAdded => "Added a page of a learner's marked paper.",
            self::PaperChanged => "Removed or moved a page of a learner's marked paper.",
            self::CommentWritten => "Wrote a report card comment for a learner's term.",
            self::ResultsPublished => "Published a class's term results and report cards.",
            self::CardSettingsSaved => 'Changed what report cards show.',
            self::Observed => 'Recorded observations of children in a room.',
            self::ReviewSaved => "Saved a child's progress review.",
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::MarkCorrected;
    }
}
