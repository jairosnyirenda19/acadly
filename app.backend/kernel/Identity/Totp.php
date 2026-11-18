<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/**
 * Time-based one-time codes (RFC 6238), as authenticator apps make them:
 * six digits, a new one every 30 seconds, from a shared key in base32.
 */
final class Totp
{
    public const int STEP = 30;

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new key: 20 random bytes, in base32. */
    public static function newSecret(): string
    {
        $buffer = 0;
        $bits = 0;
        $out = '';

        // Five bits at a time from the bytes; 20 bytes make 32 characters.
        foreach (str_split(random_bytes(20)) as $byte) {
            $buffer = (($buffer << 8) | ord($byte)) & 0xFFFF;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        return $out;
    }

    /** The code for a time step. */
    public static function code(string $secret, int $step): string
    {
        $key = self::decode($secret);
        $counter = pack('N*', 0, $step);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code matches, one step either side of now for a slow clock,
     * or null. A step at or before \$after is refused: a code is used once.
     */
    public static function match(string $secret, string $code, int $now, ?int $after = null): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);
        $current = intdiv($now, self::STEP);

        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (($after === null || $step > $after) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The address an authenticator app opens: otpauth://totp/... */
    public static function address(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&digits=6&period='.self::STEP;
    }

    private static function decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret))) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
