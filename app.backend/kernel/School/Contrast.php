<?php

declare(strict_types=1);

namespace Acadly\Kernel\School;

/** How readable one colour is on another: the WCAG contrast ratio of two `#RRGGBB` colours. */
final class Contrast
{
    /** The register book's own colours, used when a school chooses none. */
    public const string MAIN = '#0C2340';

    public const string ACCENT = '#2FD6CF';

    /** White text on the main colour: the menu, dark blocks. */
    public const float TEXT = 4.5;

    /** The accent on the main colour: the name's last word, the current page's edge, numbers. */
    public const float ACCENT_ON_MAIN = 3.0;

    public static function isColour(string $hex): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    /** The colour made darker (amount below 0) or lighter (above 0), by a share of the way to black or white. */
    public static function shade(string $hex, float $amount): string
    {
        $out = '#';

        foreach ([1, 3, 5] as $i) {
            $c = hexdec(substr($hex, $i, 2));
            $c = $amount < 0 ? $c * (1 + $amount) : $c + (255 - $c) * $amount;
            $out .= str_pad(dechex((int) round(max(0, min(255, $c)))), 2, '0', STR_PAD_LEFT);
        }

        return strtoupper($out);
    }

    /** The nearest darker shade of the main colour that white text can be read on. */
    public static function nearestMain(string $hex): string
    {
        foreach (range(0, 20) as $i) {
            $try = self::shade($hex, -0.05 * $i);

            if (self::ratio('#FFFFFF', $try) >= self::TEXT) {
                return $try;
            }
        }

        return self::MAIN;
    }

    /** The nearest lighter shade of the accent that reads on the main colour. */
    public static function nearestAccent(string $hex, string $main): string
    {
        foreach (range(0, 20) as $i) {
            $try = self::shade($hex, 0.05 * $i);

            if (self::ratio($try, $main) >= self::ACCENT_ON_MAIN) {
                return $try;
            }
        }

        return '#FFFFFF';
    }

    private static function luminance(string $hex): float
    {
        $channel = function (int $i) use ($hex): float {
            $c = hexdec(substr($hex, $i, 2)) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(1) + 0.7152 * $channel(3) + 0.0722 * $channel(5);
    }
}
