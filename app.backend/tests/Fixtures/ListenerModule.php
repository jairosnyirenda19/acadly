<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Access\Events\RoleAssigned;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;

/** A module that reacts to role assignments, for the outbox tests. */
final class ListenerModule implements Module
{
    public function key(): string
    {
        return 'listener';
    }

    public function name(): string
    {
        return 'Listener';
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
        return [];
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
        return [RoleAssigned::class => [RecordingConsumer::class]];
    }

    public function journeys(): array
    {
        return [];
    }
}
