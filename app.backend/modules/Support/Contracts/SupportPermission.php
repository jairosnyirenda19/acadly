<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Contracts;

use Acadly\Kernel\Access\Permission;

enum SupportPermission: string implements Permission
{
    case View = 'support.view';
    case Lead = 'support.lead';
    case Confidential = 'support.confidential';
    case Manage = 'support.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See support and trends',
            self::Lead => 'Lead support plans',
            self::Confidential => 'See confidential support plans',
            self::Manage => 'Set the support rules',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'The learners to watch, their trends by term and their support plans, in the classes where the role is given. Confidential plans are not shown.',
            self::Lead => 'Open and lead a support plan, review it and close it, and set a signal aside with a reason, in the classes where the role is given.',
            self::Confidential => 'See and lead confidential support plans, such as counselling, in the classes where the role is given. Grant to the people who lead pastoral care only.',
            self::Manage => 'Set the rules that put a learner on the list to watch, and how plans start.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View || $this === self::Confidential;
    }
}
