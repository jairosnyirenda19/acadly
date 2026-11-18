<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Contracts;

use Acadly\Kernel\Access\Permission;

enum ConductPermission: string implements Permission
{
    case View = 'conduct.view';
    case Record = 'conduct.record';
    case FollowUp = 'conduct.follow_up';
    case Manage = 'conduct.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See conduct',
            self::Record => 'Record conduct',
            self::FollowUp => 'Decide serious incidents',
            self::Manage => 'Set the conduct rules',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'The incidents and commendations of the learners in the classes where the role is given.',
            self::Record => 'Record an incident or a commendation for the learners in the classes where the role is given, and withdraw one\'s own entry with a reason.',
            self::FollowUp => 'Look into a serious incident, keep its notes and statements, decide the outcome for each learner, and close it. Statements are confidential.',
            self::Manage => 'Set the kinds of incident and commendation, when families are told, and the rules for learners to watch. Withdraw any entry with a reason.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
