<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/** Where a student stands in a programme (D-098). Only an active student is registered for a semester. */
enum StudentStatus: string
{
    case Active = 'active';
    case Leave = 'leave';
    case Withdrawn = 'withdrawn';
    case Completed = 'completed';
    case Discontinued = 'discontinued';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Leave => 'Leave of absence',
            self::Withdrawn => 'Withdrawn',
            self::Completed => 'Curriculum completed',
            self::Discontinued => 'Discontinued',
        };
    }
}
