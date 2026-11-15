<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;

/** A division of the school, with the profile it is pinned to. */
final readonly class DivisionView
{
    public function __construct(
        public string $id,
        public string $name,
        public CompiledProfile $profile,
    ) {}
}
