<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/**
 * Fills `:token` placeholders (04 §6). One pass, so a value that contains a
 * colon is never read as a token. A token with nothing behind it prints as
 * a dash, never as `:token`.
 */
final class Words
{
    public const string PATTERN = '/:([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)/';

    /** @param  array<string, string|null>  $tokens */
    public static function fill(string $template, array $tokens): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($tokens): string {
                $value = $tokens[$match[1]] ?? null;

                return $value !== null && trim($value) !== '' ? $value : '—';
            },
            $template,
        );
    }

    /** @return list<string> the tokens a template names */
    public static function tokensIn(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
