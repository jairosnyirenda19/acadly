<?php

declare(strict_types=1);

namespace Acadly\Kernel\Support;

use Illuminate\Support\Str;

/**
 * Every primary key in Acadly is a UUID, version 7, made here
 * (docs/architecture/05-data-and-api.md §1). Version 7 is time-ordered, so
 * indexes stay compact.
 */
final class Ids
{
    public static function next(): string
    {
        return (string) Str::uuid7();
    }

    public static function isValid(string $value): bool
    {
        return Str::isUuid($value);
    }
}
