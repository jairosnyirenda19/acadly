<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Illuminate\Validation\ValidationException;

/**
 * Reads the compact binary encoding a phone uses for its passkey (CBOR,
 * RFC 8949): only what a passkey holds, that is numbers, bytes, text,
 * lists, maps, true, false and null.
 */
final class Cbor
{
    private int $at = 0;

    public function __construct(private readonly string $bytes) {}

    public function read(): mixed
    {
        $first = $this->take(1);
        $major = ord($first) >> 5;
        $info = ord($first) & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => self::refuse(),
            };
        }
        $n = $this->length($info);

        return match ($major) {
            0 => $n,
            1 => -1 - $n,
            2, 3 => $this->take($n),
            4 => $this->list($n),
            5 => $this->map($n),
            default => self::refuse(),
        };
    }

    private function length(int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => self::refuse(),
        };
        $n = 0;
        foreach (str_split($this->take($size)) as $b) {
            $n = ($n << 8) | ord($b);
        }

        return $n >= 0 && $n <= 1_000_000 ? $n : self::refuse();
    }

    /** @return list<mixed> */
    private function list(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->read();
        }

        return $out;
    }

    /** @return array<int|string, mixed> */
    private function map(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $key = $this->read();
            $out[is_int($key) || is_string($key) ? $key : self::refuse()] = $this->read();
        }

        return $out;
    }

    private function take(int $n): string
    {
        if ($n < 0 || $this->at + $n > strlen($this->bytes)) {
            self::refuse();
        }
        $part = substr($this->bytes, $this->at, $n);
        $this->at += $n;

        return $part;
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages(['phone' => ['This is not your attendance phone.']]);
    }
}
