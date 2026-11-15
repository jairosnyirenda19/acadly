<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/** A campus of the school. */
final readonly class CampusView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $place,
        public ?string $address,
    ) {}
}
