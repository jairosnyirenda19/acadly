<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/** How a term grade is made (11 §4). */
enum PlanMode: string
{
    case Continuous = 'continuous';
    case ExamOnly = 'exam_only';

    public function label(): string
    {
        return match ($this) {
            self::Continuous => 'Continuous',
            self::ExamOnly => 'Exam only',
        };
    }
}
