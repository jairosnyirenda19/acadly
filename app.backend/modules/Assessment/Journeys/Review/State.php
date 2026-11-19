<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Journeys\Review;

use Acadly\Kernel\Blueprint\Journey\JourneyState;

enum State: string implements JourneyState
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Checked = 'checked';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready to check',
            self::Checked => 'Checked',
            self::Shared => 'Shared',
        };
    }
}
