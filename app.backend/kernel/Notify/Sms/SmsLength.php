<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Sms;

/**
 * How many SMS units a text uses (GSM 03.38).
 *
 * Plain text uses the GSM alphabet: 160 characters in one SMS, 153 in each
 * part of a longer one. The extension characters ( ^ { } \ [ ] ~ | € ) count
 * twice. A text with any other character, such as ŵ or an emoji, is sent as
 * Unicode: 70 in one SMS, 67 in each part, counted in UTF-16 units.
 */
final class SmsLength
{
    private const string BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const string EXTENSION = "^{}\\[~]|€\f";

    /** @return array{characters: int, units: int, unicode: bool} */
    public static function of(string $text): array
    {
        $septets = 0;
        $unicode = false;

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::BASIC, $char) !== false) {
                $septets++;
            } elseif (mb_strpos(self::EXTENSION, $char) !== false) {
                $septets += 2;
            } else {
                $unicode = true;

                break;
            }
        }

        if ($unicode) {
            $length = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

            return ['characters' => $length, 'units' => $length <= 70 ? 1 : (int) ceil($length / 67), 'unicode' => true];
        }

        return ['characters' => $septets, 'units' => $septets <= 160 ? 1 : (int) ceil($septets / 153), 'unicode' => false];
    }
}
