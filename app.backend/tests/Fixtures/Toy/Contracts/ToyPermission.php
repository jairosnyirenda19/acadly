<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Contracts;

use Acadly\Kernel\Access\Permission;

enum ToyPermission: string implements Permission
{
    case Submit = 'toy.submit';
    case Decide = 'toy.decide';
    case Complete = 'toy.complete';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return ucfirst(substr($this->value, 4)).' errands';
    }

    public function help(): string
    {
        return 'For the journey tests.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
