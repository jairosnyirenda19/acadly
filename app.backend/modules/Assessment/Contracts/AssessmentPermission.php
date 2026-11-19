<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

use Acadly\Kernel\Access\Permission;

/** The assessment module's permissions (11 §9). */
enum AssessmentPermission: string implements Permission
{
    case Create = 'assessment.create';
    case Enter = 'assessment.enter';
    case Lock = 'assessment.lock';
    case Observe = 'assessment.observe';
    case Plan = 'assessment.plan';
    case Publish = 'assessment.publish';
    case Review = 'assessment.review';
    case View = 'assessment.view';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Set assessments',
            self::Enter => 'Enter marks',
            self::Lock => 'Lock or return marks',
            self::Observe => 'Record observations',
            self::Plan => 'Keep scales and grading plans',
            self::Publish => 'Publish results',
            self::Review => 'Check and share progress reviews',
            self::View => 'See assessments and term grades',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Create => 'Set assessments for the classes and subjects you teach.',
            self::Enter => 'Enter marks for your own assessments while they are open, and submit them.',
            self::Observe => 'Record observations of the children in your room, and draft their progress reviews. Levels that record observations only.',
            self::Lock => 'Lock or return submitted marks, and correct a locked mark with a reason. Never marks you submitted.',
            self::Plan => 'Keep the grading scales and the grading plans that say how a term grade is made.',
            self::Review => 'Check progress reviews, return them with a reason, and share checked reviews with families. Never a review you drafted.',
            self::Publish => 'Publish term results and report cards to families, write the head teacher comment, and keep the report card settings. Never results of marks you submitted.',
            self::View => 'See assessments, marks and term grades where the role is given, without changing them.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
