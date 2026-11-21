<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Contracts;

use Acadly\Kernel\Access\Permission;

/** Lectures' permissions (D-102). Each is held at a division or the whole school. */
enum LecturesPermission: string implements Permission
{
    case View = 'lectures.view';
    case Timetable = 'lectures.timetable';
    case Cover = 'lectures.cover';
    case Teach = 'lectures.teach';
    case Phones = 'lectures.phones';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See the lecture timetable and lecture attendance',
            self::Timetable => 'Set rooms and the lecture timetable',
            self::Cover => 'Arrange cover for lectures',
            self::Teach => 'Take attendance at your own lectures',
            self::Phones => 'Reset a student\'s attendance phone',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'See the rooms, the lecture timetable, cover, and the lecture attendance of every course in the division.',
            self::Timetable => 'Add and change rooms and the campus area, set how long scanning stays open, and build, check and publish each semester\'s lecture timetable.',
            self::Cover => 'Name a colleague to cover a lecture, or cancel or move it, with a reason. Usually the head of department.',
            self::Teach => 'Open and close attendance at the lectures you give or cover, mark students by hand with a reason, tell the head of department you cannot come, and ask a colleague to cover.',
            self::Phones => 'Reset a student\'s attendance phone when it is lost or changed, with a reason. Usually the registry.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
