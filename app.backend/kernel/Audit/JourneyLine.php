<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** The journey step an audit line records: the pinned ref, the step and its outcome. */
final readonly class JourneyLine
{
    public function __construct(
        public string $ref,
        public string $step,
        public string $outcome,
    ) {}
}
