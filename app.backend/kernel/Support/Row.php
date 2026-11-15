<?php

declare(strict_types=1);

namespace Acadly\Kernel\Support;

use LogicException;

/**
 * Reads typed values out of a database row. A column of the wrong type is a
 * fault in the schema or the query, so it stops the request.
 */
final class Row
{
    public static function text(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : throw new LogicException("The column [{$column}] is not text.");
    }

    public static function maybeText(object $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : null;
    }

    public static function int(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => throw new LogicException("The column [{$column}] is not a number."),
        };
    }
}
