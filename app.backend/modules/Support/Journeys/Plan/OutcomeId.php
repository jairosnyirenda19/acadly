<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Journeys\Plan;

enum OutcomeId: string
{
    case Agreed = 'agreed';
    case GoalsMet = 'goals_met';
    case PartlyMet = 'partly_met';
    case Referred = 'referred';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Agreed => 'Plan agreed',
            self::GoalsMet => 'Goals met',
            self::PartlyMet => 'Partly met',
            self::Referred => 'Referred on',
            self::Left => 'Left the school',
        };
    }
}
