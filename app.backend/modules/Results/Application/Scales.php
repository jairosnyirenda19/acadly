<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Grading scales and settings (D-105). A scale never changes once saved: a
 * change is a new scale from a named semester on. With none saved, the
 * standard scale applies: A to F, 4.0 to 0, pass at 50.
 *
 * @phpstan-type Band array{grade: string, min: int, max: int, points: float}
 * @phpstan-type Scale array{id: string|null, label: string, pass_mark: int, bands: list<Band>, from_on: string|null}
 * @phpstan-type Settings array{bar_percent: int, bar_days: int, hold_slip_for_fees: bool}
 */
final class Scales
{
    /** @var list<Band> */
    public const array STANDARD = [
        ['grade' => 'A', 'min' => 80, 'max' => 100, 'points' => 4.0],
        ['grade' => 'B+', 'min' => 75, 'max' => 79, 'points' => 3.5],
        ['grade' => 'B', 'min' => 70, 'max' => 74, 'points' => 3.0],
        ['grade' => 'C+', 'min' => 65, 'max' => 69, 'points' => 2.5],
        ['grade' => 'C', 'min' => 60, 'max' => 64, 'points' => 2.0],
        ['grade' => 'D', 'min' => 50, 'max' => 59, 'points' => 1.0],
        ['grade' => 'F', 'min' => 0, 'max' => 49, 'points' => 0.0],
    ];

    /** @return Scale */
    public static function standard(): array
    {
        return ['id' => null, 'label' => 'Standard scale', 'pass_mark' => 50, 'bands' => self::STANDARD, 'from_on' => null];
    }

    /**
     * The scale in force for a semester that starts on a day.
     *
     * @return Scale
     */
    public static function for(string $divisionId, string $startsOn): array
    {
        $row = DB::table('results_scales')->where('division_id', $divisionId)->where('from_on', '<=', $startsOn)->orderByDesc('from_on')->orderByDesc('created_at')->first();

        return $row === null ? self::standard() : self::row($row);
    }

    /** @return list<Scale> */
    public static function saved(string $divisionId): array
    {
        return array_values(array_map(fn (object $r): array => self::row($r),
            DB::table('results_scales')->where('division_id', $divisionId)->orderByDesc('from_on')->orderByDesc('created_at')->get()->all()));
    }

    /**
     * The grade and its points for a whole mark.
     *
     * @param  Scale  $scale
     * @return array{grade: string, points: float}
     */
    public static function grade(array $scale, int $mark): array
    {
        foreach ($scale['bands'] as $b) {
            if ($mark >= $b['min'] && $mark <= $b['max']) {
                return ['grade' => $b['grade'], 'points' => $b['points']];
            }
        }
        $last = $scale['bands'][count($scale['bands']) - 1];

        return ['grade' => $last['grade'], 'points' => $last['points']];
    }

    /** A mark rounded to a whole number, .5 going up. */
    public static function whole(float $mark): int
    {
        return (int) floor(round($mark, 4) + 0.5);
    }

    /**
     * A credit-weighted grade point average, to 2 decimals, or null with no credits.
     *
     * @param  list<array{points: float, credits: int}>  $courses
     */
    public static function gpa(array $courses): ?float
    {
        $credits = array_sum(array_column($courses, 'credits'));

        return $credits === 0 ? null : round(array_sum(array_map(fn (array $c): float => $c['points'] * $c['credits'], $courses)) / $credits, 2);
    }

    /** @return Settings */
    public static function settings(string $divisionId): array
    {
        $s = DB::table('results_settings')->where('division_id', $divisionId)->first();

        return $s === null ? ['bar_percent' => 80, 'bar_days' => 14, 'hold_slip_for_fees' => false]
            : ['bar_percent' => Row::int($s, 'bar_percent'), 'bar_days' => Row::int($s, 'bar_days'), 'hold_slip_for_fees' => (bool) $s->hold_slip_for_fees];
    }

    /** @return Scale */
    private static function row(object $r): array
    {
        $bands = [];
        $raw = json_decode(Row::text($r, 'bands'), true);
        foreach (is_array($raw) ? $raw : [] as $b) {
            if (is_array($b)) {
                $bands[] = ['grade' => is_string($b['grade'] ?? null) ? $b['grade'] : '', 'min' => is_numeric($b['min'] ?? null) ? (int) $b['min'] : 0,
                    'max' => is_numeric($b['max'] ?? null) ? (int) $b['max'] : 0, 'points' => is_numeric($b['points'] ?? null) ? (float) $b['points'] : 0.0];
            }
        }

        return ['id' => Row::text($r, 'id'), 'label' => Row::text($r, 'label'), 'pass_mark' => Row::int($r, 'pass_mark'), 'bands' => $bands === [] ? self::STANDARD : $bands,
            'from_on' => Row::text($r, 'from_on')];
    }
}
