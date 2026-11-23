<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The rules the examiners' board works from (D-106): standing, carried
 * credits, attempts, the time limit, the classes of award and whether the
 * certificate waits for fees. A saved set never changes: a change is a new
 * set from a named semester on. With none saved, the standard rules apply.
 *
 * @phpstan-type ClassBand array{name: string, from: float}
 * @phpstan-type RuleSet array{id: string|null, good_cgpa: float, carry_credits: int, max_attempts: int, time_factor: int, classes: array{diploma: list<ClassBand>, degree: list<ClassBand>}, hold_certificate: bool, from_on: string|null}
 */
final class Rules
{
    /** @return RuleSet */
    public static function standard(): array
    {
        return ['id' => null, 'good_cgpa' => 2.0, 'carry_credits' => 30, 'max_attempts' => 3, 'time_factor' => 2,
            'classes' => [
                'diploma' => [['name' => 'Distinction', 'from' => 3.6], ['name' => 'Credit', 'from' => 3.0], ['name' => 'Pass', 'from' => 2.0]],
                'degree' => [['name' => 'First class', 'from' => 3.6], ['name' => 'Upper second', 'from' => 3.0], ['name' => 'Lower second', 'from' => 2.5], ['name' => 'Pass', 'from' => 2.0]],
            ],
            'hold_certificate' => true, 'from_on' => null];
    }

    /** @return RuleSet */
    public static function for(string $divisionId, string $startsOn): array
    {
        $row = DB::table('results_rules')->where('division_id', $divisionId)->where('from_on', '<=', $startsOn)->orderByDesc('from_on')->orderByDesc('created_at')->first();
        if ($row === null) {
            return self::standard();
        }
        $classes = json_decode(Row::text($row, 'classes'), true);
        $std = self::standard()['classes'];

        return ['id' => Row::text($row, 'id'), 'good_cgpa' => (float) Row::text($row, 'good_cgpa'), 'carry_credits' => Row::int($row, 'carry_credits'),
            'max_attempts' => Row::int($row, 'max_attempts'), 'time_factor' => Row::int($row, 'time_factor'),
            'classes' => ['diploma' => self::bands(is_array($classes) ? ($classes['diploma'] ?? null) : null, $std['diploma']), 'degree' => self::bands(is_array($classes) ? ($classes['degree'] ?? null) : null, $std['degree'])],
            'hold_certificate' => (bool) $row->hold_certificate, 'from_on' => Row::text($row, 'from_on')];
    }

    /** Which list of classes an award uses: degrees, or diplomas and certificates. */
    public static function scheme(string $award): string
    {
        return in_array($award, ['bachelor', 'master', 'doctorate'], true) ? 'degree' : 'diploma';
    }

    /**
     * The class of award for a final cumulative GPA, or null under the lowest class.
     *
     * @param  RuleSet  $rules
     */
    public static function classOf(array $rules, string $award, float $cgpa): ?string
    {
        $bands = $rules['classes'][self::scheme($award)];
        usort($bands, fn (array $a, array $b): int => $b['from'] <=> $a['from']);
        foreach ($bands as $b) {
            if ($cgpa >= $b['from']) {
                return $b['name'];
            }
        }

        return null;
    }

    /**
     * The lowest cumulative GPA that completes a programme.
     *
     * @param  RuleSet  $rules
     */
    public static function passFrom(array $rules, string $award): float
    {
        $from = array_column($rules['classes'][self::scheme($award)], 'from');

        return $from === [] ? 0.0 : (float) min($from);
    }

    /**
     * @param  list<ClassBand>  $fallback
     * @return list<ClassBand>
     */
    private static function bands(mixed $list, array $fallback): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $b) {
            if (is_array($b) && is_string($b['name'] ?? null) && is_numeric($b['from'] ?? null)) {
                $out[] = ['name' => $b['name'], 'from' => (float) $b['from']];
            }
        }

        return $out === [] ? $fallback : $out;
    }
}
