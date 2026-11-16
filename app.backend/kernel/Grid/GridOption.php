<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Closure;
use Illuminate\Database\Query\Builder;

/** One option of a choice filter: its value, its label, and the condition a matching row meets. */
final readonly class GridOption
{
    /** @param Closure(Builder): mixed $apply */
    public function __construct(
        public string $value,
        public string $label,
        public Closure $apply,
    ) {}
}
