<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Domain;

/** The named outcome of a serious incident for one learner. */
enum Sanction: string
{
    case NoAction = 'no_action';
    case Warning = 'warning';
    case Detention = 'detention';
    case Suspension = 'suspension';
    case Referred = 'referred';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No further action',
            self::Warning => 'Warning',
            self::Detention => 'Detention',
            self::Suspension => 'Suspension',
            self::Referred => 'Referred to the board',
        };
    }

    /** Detention and suspension have a first day and a length. */
    public function hasDays(): bool
    {
        return $this === self::Detention || $this === self::Suspension;
    }
}
