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
    case Register = 'programmes.register';
    case Approve = 'programmes.approve';
    case AllowFees = 'programmes.allow_fees';
    case Accounts = 'programmes.accounts';

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
            self::Register => 'Place students and register them for a semester',
            self::Approve => 'Approve semester registrations',
            self::AllowFees => 'Allow registration without the fees needed',
            self::Accounts => 'Look after student accounts',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'See the faculties, departments, programmes, curricula, the course catalogue and the semester offerings.',
            self::Manage => 'Add and change faculties, departments, programmes and courses, and release curriculum versions.',
            self::Offer => 'Prepare each semester\'s offerings, split courses into groups and name their lecturers.',
            self::Register => 'Place students in programmes, set the registration dates, start a semester, choose courses for a student, complete registrations and change courses in the add and drop period.',
            self::Approve => 'Approve or return the semester registrations of the division. Usually the head of department.',
            self::AllowFees => 'Let a student\'s registration complete before the share of fees needed is paid, with a reason. Usually the bursar.',
            self::Accounts => 'Keep each student\'s phone for codes, send set-up codes, and suspend or restore a student\'s sign-in, with a reason. Never sees a password.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
