<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use InvalidArgumentException;

/** Reads values out of a decoded compiled blueprint, refusing anything of the wrong shape. */
final class Shape
{
    /** @param  array<mixed>  $from */
    public static function string(array $from, string $key): string
    {
        $value = $from[$key] ?? null;

        return is_string($value) ? $value : throw self::malformed($key);
    }

    /** @param  array<mixed>  $from */
    public static function nullableString(array $from, string $key): ?string
    {
        $value = $from[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw self::malformed($key);
    }

    /** @param  array<mixed>  $from */
    public static function bool(array $from, string $key): bool
    {
        $value = $from[$key] ?? null;

        return is_bool($value) ? $value : throw self::malformed($key);
    }

    /**
     * @param  array<mixed>  $from
     * @return list<array<mixed>>
     */
    public static function records(array $from, string $key): array
    {
        $out = [];

        foreach (self::list($from, $key) as $item) {
            $out[] = is_array($item) ? $item : throw self::malformed($key);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $from
     * @return list<string>
     */
    public static function strings(array $from, string $key): array
    {
        $out = [];

        foreach (self::list($from, $key) as $item) {
            $out[] = is_string($item) ? $item : throw self::malformed($key);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $from
     * @return array<string, scalar|null>
     */
    public static function scalars(array $from, string $key): array
    {
        $value = $from[$key] ?? [];
        $out = [];

        if (! is_array($value)) {
            throw self::malformed($key);
        }

        foreach ($value as $name => $item) {
            if (! is_string($name) || ! (is_scalar($item) || $item === null)) {
                throw self::malformed($key);
            }

            $out[$name] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $from
     * @return array<string, list<string>>
     */
    public static function groups(array $from, string $key): array
    {
        $value = $from[$key] ?? [];
        $out = [];

        if (! is_array($value)) {
            throw self::malformed($key);
        }

        foreach ($value as $name => $items) {
            if (! is_string($name) || ! is_array($items)) {
                throw self::malformed($key);
            }

            $out[$name] = self::strings(['items' => $items], 'items');
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $from
     * @return list<mixed>
     */
    private static function list(array $from, string $key): array
    {
        $value = $from[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : throw self::malformed($key);
    }

    private static function malformed(string $key): InvalidArgumentException
    {
        return new InvalidArgumentException("The compiled blueprint has a malformed or missing [{$key}].");
    }
}
