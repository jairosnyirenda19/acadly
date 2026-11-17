<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/**
 * The parent portal's address at the end of a text message (D-083). The
 * address is the school's own (`acadly.portal_url`), written as families
 * type it: no `https://`, no trailing slash. With no address, nothing is
 * added.
 */
final class PortalLink
{
    /** The address as people type it, such as sunrise.acadly.mw/p, or null when the school has none. */
    public static function address(): ?string
    {
        $url = config('acadly.portal_url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim((string) preg_replace('#^https?://#i', '', trim($url)), '/');
    }

    /** The sentence added to the message, or null when the school has no portal address. */
    public static function sentence(): ?string
    {
        $address = self::address();

        return $address !== null ? 'See it on your phone: '.$address : null;
    }

    /** The words, followed by the sentence when the notice opens the portal. */
    public static function after(Notice $notice, string $words): string
    {
        $sentence = $notice->opensPortal() ? self::sentence() : null;

        return $sentence !== null ? rtrim($words).' '.$sentence : $words;
    }
}
