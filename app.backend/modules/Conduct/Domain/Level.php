<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Domain;

/** How serious an incident is. A serious one opens a case the office follows up. */
enum Level: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Serious = 'serious';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Moderate => 'Moderate',
            self::Serious => 'Serious',
        };
    }
}
