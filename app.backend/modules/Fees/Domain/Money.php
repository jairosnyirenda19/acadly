<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Domain;

/**
 * Money is a whole number of tambala (100 to the kwacha), never a decimal.
 * Shares are split so that the parts always add up to the whole: each part is
 * rounded down and the last part takes what is left.
 */
final class Money
{
    /** "MK 150,000" or "MK 150,000.50". */
    public static function text(int $minor): string
    {
        $sign = $minor < 0 ? '−' : '';
        $minor = abs($minor);
        $kwacha = intdiv($minor, 100);
        $tambala = $minor % 100;

        return $sign.'MK '.number_format($kwacha).($tambala > 0 ? '.'.str_pad((string) $tambala, 2, '0', STR_PAD_LEFT) : '');
    }

    /** A short amount for a sentence or a chart: "MK 220.4m", "MK 45,000". */
    public static function short(int $minor): string
    {
        $kwacha = intdiv(abs($minor), 100);
        $sign = $minor < 0 ? '−' : '';

        return $kwacha >= 1_000_000 ? $sign.'MK '.rtrim(rtrim(number_format($kwacha / 1_000_000, 1), '0'), '.').'m' : $sign.'MK '.number_format($kwacha);
    }

    /**
     * A total split by shares in basis points (10000 is the whole).
     *
     * @param  list<int>  $shares
     * @return list<int>
     */
    public static function split(int $total, array $shares): array
    {
        $parts = [];
        $given = 0;

        foreach ($shares as $i => $bp) {
            $part = $i === count($shares) - 1 ? $total - $given : intdiv($total * $bp, 10000);
            $parts[] = $part;
            $given += $part;
        }

        return $parts;
    }
}
