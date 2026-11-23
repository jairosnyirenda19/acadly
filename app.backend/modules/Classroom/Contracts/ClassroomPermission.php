<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Contracts;

use Acadly\Kernel\Access\Permission;

/** Classroom's permissions (D-104). Each is held at a division or the whole school. */
enum ClassroomPermission: string implements Permission
{
    case View = 'classroom.view';
    case Teach = 'classroom.teach';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See every course page, hand-in and mark',
            self::Teach => 'Run the course pages of your own courses',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'See the course pages of the division: resources, assignments, hand-ins, marks and continuous assessment. Usually the head of department and the registry.',
            self::Teach => 'For the courses you are named on as lecturer: add resources, post announcements, set assignments and exam papers, mark hand-ins, give feedback and release marks.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
