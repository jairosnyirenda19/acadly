<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy;

use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Tests\Fixtures\Toy\Contracts\ErrandCompleted;
use Tests\Fixtures\Toy\Contracts\ToyPermission;

/** A toy module with one journey, for the journey engine and conformance tests (08, stage 1 exit). */
final class ToyModule implements Module
{
    public function key(): string
    {
        return 'toy';
    }

    public function name(): string
    {
        return 'Errands';
    }

    public function tier(): Tier
    {
        return Tier::Core;
    }

    public function requires(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ToyPermission::cases();
    }

    public function auditActions(): array
    {
        return [];
    }

    public function events(): array
    {
        return [ErrandCompleted::class];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return ['toy.errand'];
    }
}
