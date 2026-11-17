<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Access\Permission;

enum EnrolmentPermission: string implements Permission
{
    case View = 'enrolment.learners.view';
    case Admit = 'enrolment.admit';
    case Manage = 'enrolment.manage';
    case Export = 'enrolment.export';
    case Families = 'enrolment.families';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See learners',
            self::Admit => 'Admit learners',
            self::Manage => 'Keep learner records',
            self::Export => 'Export learner lists',
            self::Families => 'Keep parent access',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'The learners in the classes where the role is given: their details, class history, contacts and photo.',
            self::Admit => 'Admit a learner into a class where the role is given.',
            self::Manage => 'Correct a learner\'s details, change their class, and keep their contacts and photo up to date.',
            self::Export => 'Download a spreadsheet of the learners in the classes where the role is given, with their main contact.',
            self::Families => 'SEES EVERY FAMILY in the school by phone number: which children each parent sees in the parent portal. Suspend or end the access of a parent. Grant to the school office only.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View || $this === self::Families;
    }
}
