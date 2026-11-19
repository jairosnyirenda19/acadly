<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/**
 * The steps of an observation (11 §2, D-094). Drawn as strokes and always
 * shown with their word. "Not seen" is no step: the child was not seen doing
 * it that day, which is not a failure.
 */
enum ObservationStep: string
{
    case Starting = 'starting';
    case Growing = 'growing';
    case CanDo = 'can_do';
    case NotSeen = 'not_seen';

    public function label(): string
    {
        return match ($this) {
            self::Starting => 'Starting',
            self::Growing => 'Growing',
            self::CanDo => 'Can do it',
            self::NotSeen => 'Not seen',
        };
    }

    /** The strokes the step is drawn with, or none for "not seen". */
    public function strokes(): int
    {
        return match ($this) {
            self::Starting => 1,
            self::Growing => 2,
            self::CanDo => 3,
            self::NotSeen => 0,
        };
    }

    /** @return list<self> The steps a progress review may give: never "not seen". */
    public static function reached(): array
    {
        return [self::Starting, self::Growing, self::CanDo];
    }
}
