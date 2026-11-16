<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/** The kinds of assessment, a fixed list in the first release (11 §9). */
enum AssessmentKind: string
{
    case ClassTest = 'class_test';
    case WeeklyTest = 'weekly_test';
    case MidTerm = 'mid_term';
    case EndOfTerm = 'end_of_term';
    case Assignment = 'assignment';
    case Practical = 'practical';

    public function label(): string
    {
        return match ($this) {
            self::ClassTest => 'Class test',
            self::WeeklyTest => 'Weekly test',
            self::MidTerm => 'Mid-term test',
            self::EndOfTerm => 'End-of-term exam',
            self::Assignment => 'Assignment',
            self::Practical => 'Practical',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function choices(): array
    {
        return array_map(fn (self $k): array => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
