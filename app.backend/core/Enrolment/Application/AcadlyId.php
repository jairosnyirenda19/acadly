<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Acadly Learner ID (03 §4): random, readable, with a check character,
 * such as AL-7K3M-92QF. It never encodes the school, the year or the campus.
 * Letters that are easy to confuse (I, L, O, U, 0, 1) are not used.
 */
final class AcadlyId
{
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function next(): string
    {
        for ($try = 0; $try < 20; $try++) {
            $id = self::make();

            if (! DB::table('learners')->where('acadly_id', $id)->exists()) {
                return $id;
            }
        }

        throw new RuntimeException('No free Acadly ID was found.');
    }

    public static function make(): string
    {
        $body = '';

        for ($i = 0; $i < 7; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $body .= self::check($body);

        return 'AL-'.substr($body, 0, 4).'-'.substr($body, 4);
    }

    public static function isValid(string $id): bool
    {
        if (preg_match('/^AL-([2-9A-HJKMNP-TV-Z]{4})-([2-9A-HJKMNP-TV-Z]{4})$/', $id, $m) !== 1) {
            return false;
        }

        $body = $m[1].$m[2];

        return self::check(substr($body, 0, 7)) === $body[7];
    }

    /** A weighted sum, so one wrong character or two swapped neighbours are caught. */
    private static function check(string $body): string
    {
        $n = strlen(self::ALPHABET);
        $sum = 0;

        foreach (str_split($body) as $i => $char) {
            $sum += (int) strpos(self::ALPHABET, $char) * ($i + 2);
        }

        return self::ALPHABET[$sum % $n];
    }
}
