<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/** A module that declares a permission under another module's key. */
final class BadPrefixModule implements Module
{
    public function key(): string
    {
        return 'library';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [WrongPrefixPermission::Borrow];
    }
}
