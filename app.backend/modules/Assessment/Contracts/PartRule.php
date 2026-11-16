<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/** How several assessments inside one part of a grading plan combine. */
enum PartRule: string
{
    case Average = 'average';
    case BestN = 'best_n';
    case WeightedByMax = 'weighted_by_max';
    case Single = 'single';

    public function label(): string
    {
        return match ($this) {
            self::Average => 'Mean of the percentages',
            self::BestN => 'Mean of the best',
            self::WeightedByMax => 'Marks over the most marks',
            self::Single => 'One assessment expected',
        };
    }
}
