<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Access\Permission;

enum InstitutionPermission: string implements Permission
{
    case Manage = 'institution.manage';
    case Overview = 'institution.overview';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Manage => 'Manage the school',
            self::Overview => 'See the whole-school overview',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Manage => 'The school details, logo, levels, campuses, and opening the school.',
            self::Overview => 'Figures and charts of every division, and read-only lists of the whole school with each row marked by its division.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::Overview;
    }
}
