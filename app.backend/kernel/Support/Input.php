<?php

declare(strict_types=1);

namespace Acadly\Kernel\Support;

/** Reads typed values out of validated input. A value of the wrong type reads as empty. */
final class Input
{
    /** @param  array<mixed>  $input */
    public static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The trimmed text, or null when it is empty.
     *
     * @param  array<mixed>  $input
     */
    public static function maybe(array $input, string $key): ?string
    {
        $value = self::text($input, $key);

        return $value !== '' ? $value : null;
    }
}
