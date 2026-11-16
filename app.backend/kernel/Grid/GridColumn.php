<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

/**
 * One column of a list. `sort` is the SQL that orders by it (written in the
 * code, never built from what a person typed), or null when the column
 * cannot be sorted. `shown` is whether it shows before the person
 * chooses; `always` columns cannot be hidden.
 */
final readonly class GridColumn
{
    /** @param literal-string|null $sort */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $sort = null,
        public bool $shown = true,
        public bool $always = false,
    ) {}
}
