<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

/**
 * The checks a curriculum version must pass before it is released (D-097).
 * Each problem is a kind and its facts; the screens give the words.
 */
final class CurriculumChecks
{
    /**
     * @param  array{years: int, semesters: int, credits: int, min_load: int, max_load: int}  $programme
     * @param  list<array{id: string, year: int, semester: int, label: string, choose: int}>  $groups
     * @param  list<array{course_id: string, code: string, credits: int, retired: bool, year: int, semester: int, group_id: string|null, needs: list<array{id: string, code: string}>}>  $courses
     * @return array{problems: list<array<string, string|int>>, years: array<int, int>, slots: array<string, int>, total: int}
     */
    public static function run(array $programme, array $groups, array $courses): array
    {
        $problems = [];
        $slot = static fn (int $year, int $semester): int => ($year - 1) * $programme['semesters'] + $semester;

        // Where each course sits, as an order number.
        $at = [];
        foreach ($courses as $c) {
            $at[$c['course_id']] = $slot($c['year'], $c['semester']);
        }

        $options = [];
        foreach ($courses as $c) {
            if ($c['group_id'] !== null) {
                $options[$c['group_id']][] = $c['credits'];
            }
        }

        $slots = [];
        $years = [];
        for ($y = 1; $y <= $programme['years']; $y++) {
            $years[$y] = 0;
            for ($s = 1; $s <= $programme['semesters']; $s++) {
                $credits = 0;
                $any = false;

                foreach ($courses as $c) {
                    if ($c['year'] === $y && $c['semester'] === $s && $c['group_id'] === null) {
                        $credits += $c['credits'];
                        $any = true;
                    }
                }

                foreach ($groups as $g) {
                    if ($g['year'] === $y && $g['semester'] === $s) {
                        $list = $options[$g['id']] ?? [];
                        sort($list);
                        $credits += array_sum(array_slice($list, 0, $g['choose']));
                        $any = $any || $list !== [];

                        if (count($list) < $g['choose'] || $g['choose'] < 1) {
                            $problems[] = ['kind' => 'group', 'label' => $g['label'], 'year' => $y, 'semester' => $s, 'choose' => $g['choose'], 'options' => count($list)];
                        }
                    }
                }

                $slots["{$y}.{$s}"] = $credits;
                $years[$y] += $credits;

                if (! $any) {
                    $problems[] = ['kind' => 'empty', 'year' => $y, 'semester' => $s];
                } elseif ($credits < $programme['min_load'] || $credits > $programme['max_load']) {
                    $problems[] = ['kind' => 'load', 'year' => $y, 'semester' => $s, 'credits' => $credits, 'min' => $programme['min_load'], 'max' => $programme['max_load']];
                }
            }
        }

        foreach ($courses as $c) {
            if ($c['retired']) {
                $problems[] = ['kind' => 'retired', 'course' => $c['code']];
            }

            foreach ($c['needs'] as $need) {
                if (! isset($at[$need['id']]) || $at[$need['id']] >= $at[$c['course_id']]) {
                    $problems[] = ['kind' => 'needs', 'course' => $c['code'], 'needs' => $need['code']];
                }
            }
        }

        $total = array_sum($years);
        if ($total !== $programme['credits']) {
            $problems[] = ['kind' => 'total', 'credits' => $total, 'needed' => $programme['credits']];
        }

        return ['problems' => $problems, 'years' => $years, 'slots' => $slots, 'total' => $total];
    }
}
