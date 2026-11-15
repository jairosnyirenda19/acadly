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

    public function name(): string
    {
        return 'Library';
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

    public function auditActions(): array
    {
        return [];
    }

    public function events(): array
    {
        return [];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return [];
    }

    public function topics(): array
    {
        return [];
    }
}
