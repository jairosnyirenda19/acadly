<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

use Acadly\Kernel\Access\Permission;

enum AcademicsPermission: string implements Permission
{
    case Manage = 'academics.manage';
    case Teach = 'academics.teach';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Manage => 'Manage the academic calendar, classes and subjects',
            self::Teach => 'Teach classes',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Manage => 'Academic years, terms, classes, subjects, and who teaches each subject in each class.',
            self::Teach => 'Can be given subjects and classes to teach where the role is given. What a teacher may do in a class comes from their other permissions.',
        };
    }

    public function isSight(): bool
    {
        return false;
    }
}
