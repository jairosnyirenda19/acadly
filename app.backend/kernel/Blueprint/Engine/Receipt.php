<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Engine;

/** What a performed step committed. */
final readonly class Receipt
{
    /** @param  array<string, string>  $produced  effect class => id of the record it made */
    public function __construct(
        public string $completionId,
        public string $ref,
        public string $step,
        public string $outcome,
        public string $fromState,
        public string $toState,
        public array $produced,
    ) {}
}
