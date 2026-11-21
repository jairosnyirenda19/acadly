<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

/**
 * The changing code on the room screen (D-102). A new code every 10
 * seconds, made from the lecture's own secret; a code is good for 30
 * seconds, so a slow phone signal does not fail an honest scan. The code
 * names the lecture, so the phone needs nothing else; the phone's passkey
 * signs the code itself, so a scan cannot be made from a code alone.
 */
final class RoomCode
{
    public const int STEP = 10;

    private const int GOOD_FOR = 3;

    private const string PREFIX = 'AQXL';

    public static function current(string $sessionId, string $secret, int $now): string
    {
        return self::make($sessionId, $secret, intdiv($now, self::STEP));
    }

    /** Seconds until the code on the screen changes. */
    public static function changesIn(int $now): int
    {
        return self::STEP - ($now % self::STEP);
    }

    /** The lecture session a code names, or null when it is not a room code. */
    public static function session(string $code): ?string
    {
        $parts = explode('.', $code);

        return count($parts) === 4 && $parts[0] === self::PREFIX && preg_match('/^[0-9a-f-]{36}$/', $parts[1]) === 1 ? $parts[1] : null;
    }

    public static function good(string $code, string $sessionId, string $secret, int $now): bool
    {
        $parts = explode('.', $code);
        if (count($parts) !== 4 || ! ctype_digit($parts[2])) {
            return false;
        }
        $window = (int) $parts[2];
        $current = intdiv($now, self::STEP);
        if ($window > $current || $window <= $current - self::GOOD_FOR) {
            return false;
        }

        return hash_equals(self::make($sessionId, $secret, $window), $code);
    }

    private static function make(string $sessionId, string $secret, int $window): string
    {
        return self::PREFIX.'.'.$sessionId.'.'.$window.'.'.substr(hash_hmac('sha256', $sessionId.'.'.$window, $secret), 0, 20);
    }
}
