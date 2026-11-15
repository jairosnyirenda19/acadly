<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

/**
 * The canonical JSON form of a compiled blueprint: object keys sorted at every
 * depth, lists in their declared order, no extra whitespace except a final
 * pretty print for readable diffs. The hash is taken over this form.
 */
final class Canonical
{
    /** @param  array<mixed>  $value */
    public static function json(array $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @param  array<mixed>  $value */
    public static function hash(array $value): string
    {
        return hash('sha256', self::json($value));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(self::sort(...), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
