<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

use Acadly\Kernel\Access\Permission;

/** Programmes' permissions (D-097). Each is held at a division or the whole school. */
enum ProgrammesPermission: string implements Permission
{
    case View = 'programmes.view';
    case Manage = 'programmes.manage';
    case Offer = 'programmes.offer';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See programmes and courses',
            self::Manage => 'Set faculties, programmes, curricula and courses',
            self::Offer => 'Set semester offerings and lecturers',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'See the faculties, departments, programmes, curricula, the course catalogue and the semester offerings.',
            self::Manage => 'Add and change faculties, departments, programmes and courses, and release curriculum versions.',
            self::Offer => 'Prepare each semester\'s offerings, split courses into groups and name their lecturers.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
