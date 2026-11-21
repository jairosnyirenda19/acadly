<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Support\Facades\DB;

/**
 * The checks run before a lecture timetable is published (D-102). The same
 * students in two places, a room in two places and a lecturer in two places
 * stop the publish; a room with fewer seats than students is a warning.
 *
 * @phpstan-type Check array{kind: 'students'|'room'|'lecturer'|'seats', tone: 'wrong'|'attention', slots: list<string>, count: int}
 */
final class TimetableChecks
{
    public function __construct(private readonly CourseTeaching $teaching) {}

    /**
     * @return list<Check>
     */
    public function run(string $divisionId, string $periodId): array
    {
        $slots = DB::table('lecture_slots as s')->join('lecture_rooms as r', 'r.id', '=', 's.room_id')->where('s.period_id', $periodId)->where('s.division_id', $divisionId)
            ->whereNull('s.retired_at')->orderBy('s.weekday')->orderBy('s.starts')->get(['s.id', 's.offering_id', 's.weekday', 's.starts', 's.ends', 's.room_id', 's.lecturer_id', 'r.seats'])->all();
        $taken = [];
        foreach ($this->teaching->offerings($divisionId, $periodId) as $o) {
            $taken[$o['id']] = $o['taken'];
        }
        $students = [];
        $who = function (string $offering) use (&$students): array {
            return $students[$offering] ??= array_column($this->teaching->students($offering), 'learner_id');
        };

        $out = [];
        foreach ($slots as $s) {
            $n = $taken[Row::text($s, 'offering_id')] ?? 0;
            if ($n > Row::int($s, 'seats')) {
                $out[] = ['kind' => 'seats', 'tone' => 'attention', 'slots' => [Row::text($s, 'id')], 'count' => $n];
            }
        }
        $count = count($slots);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $slots[$i];
                $b = $slots[$j];
                if (Row::int($a, 'weekday') !== Row::int($b, 'weekday') || ! (Row::text($a, 'starts') < Row::text($b, 'ends') && Row::text($b, 'starts') < Row::text($a, 'ends'))) {
                    continue;
                }
                $pair = [Row::text($a, 'id'), Row::text($b, 'id')];
                if (Row::text($a, 'room_id') === Row::text($b, 'room_id')) {
                    $out[] = ['kind' => 'room', 'tone' => 'wrong', 'slots' => $pair, 'count' => 0];
                }
                if (Row::text($a, 'lecturer_id') === Row::text($b, 'lecturer_id')) {
                    $out[] = ['kind' => 'lecturer', 'tone' => 'wrong', 'slots' => $pair, 'count' => 0];
                }
                if (Row::text($a, 'offering_id') !== Row::text($b, 'offering_id')) {
                    $both = count(array_intersect($who(Row::text($a, 'offering_id')), $who(Row::text($b, 'offering_id'))));
                    if ($both > 0) {
                        $out[] = ['kind' => 'students', 'tone' => 'wrong', 'slots' => $pair, 'count' => $both];
                    }
                }
            }
        }
        usort($out, fn (array $x, array $y): int => [$x['tone'] === 'wrong' ? 0 : 1] <=> [$y['tone'] === 'wrong' ? 0 : 1]);

        return $out;
    }

    /** @param  list<Check>  $checks */
    public static function blocks(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['tone'] === 'wrong') {
                return true;
            }
        }

        return false;
    }
}
