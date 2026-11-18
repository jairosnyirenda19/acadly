<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Illuminate\Http\Request;

/** Reads typed values out of validated fee forms. A value of the wrong type reads as empty. */
final class Form
{
    /** @return list<array<mixed>> */
    public static function rows(Request $request, string $key): array
    {
        $value = $request->input($key);
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function strings(Request $request, string $key): array
    {
        $value = $request->input($key);
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param  array<mixed>  $row */
    public static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /** @param  array<mixed>  $row */
    public static function maybe(array $row, string $key): ?string
    {
        $text = self::text($row, $key);

        return $text === '' ? null : $text;
    }

    /** @param  array<mixed>  $row */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    /** @param  array<mixed>  $row */
    public static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
