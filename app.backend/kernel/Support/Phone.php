<?php

declare(strict_types=1);

namespace Acadly\Kernel\Support;

/**
 * Phone numbers as people write them, and as a provider needs them.
 *
 * A number written with a plus sign is international already. A national
 * number (`0991 234 567`) or a bare subscriber number (`991 234 567`) takes
 * the school's country code. The rules come from configuration, so a school
 * in another country changes the configuration, not the code.
 */
final class Phone
{
    /** The international form, such as +265991234567, or null when the number cannot be read. */
    public static function international(?string $written): ?string
    {
        if ($written === null) {
            return null;
        }

        $trimmed = trim($written);
        $digits = (string) preg_replace('/\D/', '', $trimmed);
        $country = self::digitsOf(config('acadly.phone.country_code', '265'));
        $trunk = self::digitsOf(config('acadly.phone.trunk_prefix', '0'));
        $length = self::number(config('acadly.phone.subscriber_length', 9));

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+') || str_starts_with($digits, '00')) {
            $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

            return self::plausible($digits) ? '+'.$digits : null;
        }

        if ($trunk !== '' && str_starts_with($digits, $trunk) && strlen($digits) === strlen($trunk) + $length) {
            return '+'.$country.substr($digits, strlen($trunk));
        }

        if (strlen($digits) === $length) {
            return '+'.$country.$digits;
        }

        if (str_starts_with($digits, $country) && strlen($digits) === strlen($country) + $length) {
            return '+'.$digits;
        }

        return null;
    }

    /** The number for people to read: a national number in the school's country, otherwise the international form. */
    public static function forPeople(?string $written): ?string
    {
        $international = self::international($written);

        if ($international === null) {
            return $written;
        }

        $country = self::digitsOf(config('acadly.phone.country_code', '265'));
        $trunk = self::digitsOf(config('acadly.phone.trunk_prefix', '0'));

        if (! str_starts_with($international, '+'.$country)) {
            return $international;
        }

        $national = $trunk.substr($international, strlen($country) + 1);

        return trim(substr($national, 0, 4).' '.substr($national, 4, 3).' '.substr($national, 7));
    }

    private static function plausible(string $digits): bool
    {
        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1;
    }

    private static function digitsOf(mixed $value): string
    {
        return is_scalar($value) ? (string) preg_replace('/\D/', '', (string) $value) : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 9;
    }
}
