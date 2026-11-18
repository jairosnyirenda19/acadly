<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Contracts;

use Acadly\Kernel\Access\Permission;

enum FeesPermission: string implements Permission
{
    case View = 'fees.view';
    case Collect = 'fees.collect';
    case Adjust = 'fees.adjust';
    case Manage = 'fees.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See fee statements',
            self::Collect => 'Receive fee payments',
            self::Adjust => 'Change what a family owes',
            self::Manage => 'Set the school fees',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'The fee statements, instalments and receipts of the learners in the classes where the role is given.',
            self::Collect => 'Record payments and give receipts, sell from the school shop, send reminders and close the day.',
            self::Adjust => 'Give a discount, bursary or waiver, agree a different instalment plan with a family, mark who takes boarding or transport, and cancel a receipt. Each needs a reason.',
            self::Manage => 'Set the fees of each term and the instalment plan, bill the term, keep the shop price list, and download what was billed and received.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
