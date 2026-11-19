<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance's part of the dashboards (D-100), for those who may see every
 * register of a division: today, the last twelve weeks by division (by class
 * inside a division), the reasons for absence, and the learners below the
 * attendance rule. Counts only: no learner is named.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class AttendanceDashboard implements DashboardPanels
{
    /** The attendance below which a learner is counted as low. */
    public const int RULE = 85;

    private const array REASONS = ['sick' => 'Illness', 'family' => 'Family', 'travel' => 'Travel', 'other' => 'Other'];

    public function __construct(
        private readonly AttendanceRates $rates,
        private readonly Teaching $teaching,
        private readonly SchoolDays $days,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function views(DashboardScope $scope): array
    {
        return $scope->sees(AttendancePermission::AllClasses) === [] ? [] : ['overview', 'attendance'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        $seen = $scope->sees(AttendancePermission::AllClasses);
        $classes = $this->rates->classes($seen, $scope->day);
        $weeks = Panels::weeks($scope->day, 12);
        $daily = $this->rates->daily(array_keys($classes), $weeks[0]['from'], $scope->day);
        [$present, $marked] = $this->sum(array_filter($daily, fn (array $r): bool => $r['day'] === $scope->day));
        $weekly = $this->weekly($daily, $weeks, fn (string $class): ?string => $classes[$class]['division_id'] ?? null);
        $trend = array_values(array_filter(array_map(fn (int $i): ?float => $this->rate($weekly, null, $i), range(6, 11)), fn (?float $v): bool => $v !== null));
        $today = Panels::kpi('in_today', $view, 11, 'In today', Panels::percent($present, $marked), 'percent', $marked === 0 ? 'No register taken yet' : "{$present} of {$marked}", $view === 'attendance' ? 'ink' : 'plain', $trend ?: null);

        if ($view === 'overview') {
            if ($scope->school) {
                return [$today, $this->lines($scope, $seen, $weekly, $weeks, $view, 30)];
            }
            $late = $this->notTaken($classes, $scope->day);

            return [
                $today,
                Panels::kpi('registers_late', $view, 12, 'Registers not taken', count($late), 'count', 'Of '.count($classes).' classes', $late === [] ? 'plain' : 'attention'),
                $this->classHeat($classes, $scope, $view),
            ];
        }

        if ($view !== 'attendance') {
            return [];
        }

        $from = $this->termStart($seen, $scope->day);
        $learners = $this->rates->byLearner(array_keys($classes), $from, $scope->day);
        $low = array_filter($learners, fn (array $l): bool => $l['marked'] >= 5 && $l['present'] / $l['marked'] * 100 < self::RULE);
        [$termPresent, $termMarked] = $this->sum($learners);
        $absences = $this->rates->absences(array_keys($classes), $from, $scope->day);
        $week = $weeks[11];
        $unexplained = count(array_filter($absences, fn (array $a): bool => $a['reason'] === null && $a['day'] >= $week['from']));

        $out = [
            $today,
            Panels::kpi('term', $view, 12, 'Term so far', Panels::percent($termPresent, $termMarked) ?? 0, 'percent', 'Since '.CarbonImmutable::parse($from)->format('j F')),
            Panels::kpi('low', $view, 13, 'Below '.self::RULE.'%', count($low), 'count', 'Learners this term', count($low) > 0 ? 'attention' : 'plain'),
            Panels::kpi('unexplained', $view, 14, 'No reason given', $unexplained, 'count', 'Absences this week'),
        ];

        if ($scope->school) {
            $out[] = $this->placesHeat($classes, $daily, $weeks, $scope, $view);
            $out[] = $this->lines($scope, $seen, $weekly, $weeks, $view, 40);
        } else {
            $out[] = $this->classHeat($classes, $scope, $view);
            $out[] = $this->lines($scope, $seen, $weekly, $weeks, $view, 40);
        }

        $out[] = $this->reasons($absences, $view);
        $out[] = $this->low($scope, $classes, $learners, $low, $view);

        return $out;
    }

    /**
     * @param  iterable<array{present: int, marked: int}>  $rows
     * @return array{0: int, 1: int}
     */
    private function sum(iterable $rows): array
    {
        $p = 0;
        $m = 0;
        foreach ($rows as $r) {
            $p += $r['present'];
            $m += $r['marked'];
        }

        return [$p, $m];
    }

    /**
     * Present and marked by group and week.
     *
     * @param  list<array{class_id: string, day: string, present: int, marked: int}>  $daily
     * @param  list<array{from: string, to: string, label: string}>  $weeks
     * @param  callable(string): ?string  $groupOf
     * @return array<string, array<int, array{0: int, 1: int}>> group => week index => [present, marked]
     */
    private function weekly(array $daily, array $weeks, callable $groupOf): array
    {
        $out = [];
        foreach ($daily as $r) {
            $group = $groupOf($r['class_id']);
            if ($group === null) {
                continue;
            }
            foreach ($weeks as $i => $w) {
                if ($r['day'] >= $w['from'] && $r['day'] <= $w['to']) {
                    $out[$group][$i][0] = ($out[$group][$i][0] ?? 0) + $r['present'];
                    $out[$group][$i][1] = ($out[$group][$i][1] ?? 0) + $r['marked'];
                }
            }
        }

        return $out;
    }

    /** @param  array<string, array<int, array{0: int, 1: int}>>  $weekly */
    private function rate(array $weekly, ?string $group, int $week): ?float
    {
        $p = 0;
        $m = 0;
        foreach ($weekly as $g => $byWeek) {
            if ($group === null || $g === $group) {
                $p += $byWeek[$week][0] ?? 0;
                $m += $byWeek[$week][1] ?? 0;
            }
        }

        return Panels::percent($p, $m);
    }

    /**
     * @param  list<string>  $seen
     * @param  array<string, array<int, array{0: int, 1: int}>>  $weekly
     * @param  list<array{from: string, to: string, label: string}>  $weeks
     * @return Panel
     */
    private function lines(DashboardScope $scope, array $seen, array $weekly, array $weeks, string $view, int $order): array
    {
        $series = [];
        $lowest = null;
        foreach ($seen as $id) {
            $values = array_map(fn (int $i): ?float => $this->rate($weekly, $id, $i), array_keys($weeks));
            if (array_filter($values, fn (?float $v): bool => $v !== null) === []) {
                continue;
            }
            $series[] = ['name' => $scope->name($id), 'division_id' => $id, 'dashed' => false, 'values' => $values];
            $last = $values[11] ?? $values[10] ?? null;
            if ($last !== null && ($lowest === null || $last < $lowest[1])) {
                $lowest = [$scope->name($id), $last];
            }
        }
        $finding = $lowest === null ? 'No registers in the last twelve weeks.' : (count($series) > 1 ? "{$lowest[0]} is lowest this week: {$lowest[1]}%." : "{$lowest[1]}% this week.");

        return Panels::make('attendance_by_division', $view, $order, 'half', 'line', 'Attendance · 12 weeks', count($series) > 1 ? 'Attendance by division' : 'Attendance, week by week', $finding,
            ['labels' => array_column($weeks, 'label'), 'unit' => 'percent', 'min' => 70, 'max' => 100, 'series' => $series]);
    }

    /**
     * Each division at each campus, week by week.
     *
     * @param  array<string, array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $classes
     * @param  list<array{class_id: string, day: string, present: int, marked: int}>  $daily
     * @param  list<array{from: string, to: string, label: string}>  $weeks
     * @return Panel
     */
    private function placesHeat(array $classes, array $daily, array $weeks, DashboardScope $scope, string $view): array
    {
        $campuses = DB::table('institution_campuses')->pluck('name', 'id')->all();
        $weekly = $this->weekly($daily, $weeks, fn (string $class): ?string => isset($classes[$class]) ? $classes[$class]['division_id'].'|'.$classes[$class]['campus_id'] : null);
        $rows = [];
        $below = [];
        foreach ($scope->ids() as $d) {
            foreach ($campuses as $c => $campus) {
                if (! isset($weekly["{$d}|{$c}"])) {
                    continue;
                }
                $values = array_map(fn (int $i): ?float => $this->rate($weekly, "{$d}|{$c}", $i), array_keys($weeks));
                $name = $scope->name($d).' · '.(is_string($campus) ? $campus : '');
                $rows[] = ['name' => $name, 'division_id' => $d, 'values' => $values];
                $count = count(array_filter(array_slice($values, 6), fn (?float $v): bool => $v !== null && $v < self::RULE));
                if ($count >= 2) {
                    $below[] = $name;
                }
            }
        }
        $finding = $below === [] ? 'Every division at every campus was at '.self::RULE.'% or more in the last six weeks.' : implode(' and ', $below).' fell below '.self::RULE.'% in two or more of the last six weeks.';

        return Panels::make('attendance_heat', $view, 30, 'full', 'heat', 'Each division at each campus · by week', 'Attendance, week by week', $finding,
            ['columns' => array_column($weeks, 'label'), 'rule' => self::RULE, 'rows' => $rows]);
    }

    /**
     * Each class, each day of this week.
     *
     * @param  array<string, array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $classes
     * @return Panel
     */
    private function classHeat(array $classes, DashboardScope $scope, string $view): array
    {
        $monday = CarbonImmutable::parse($scope->day)->startOfWeek(CarbonImmutable::MONDAY);
        $days = array_map(fn (int $i): string => $monday->addDays($i)->toDateString(), range(0, 4));
        $daily = $this->rates->daily(array_keys($classes), $days[0], $days[4]);
        $rows = [];
        $falling = null;
        foreach ($classes as $id => $c) {
            $values = array_map(function (string $day) use ($daily, $id): ?float {
                foreach ($daily as $r) {
                    if ($r['class_id'] === $id && $r['day'] === $day) {
                        return Panels::percent($r['present'], $r['marked']);
                    }
                }

                return null;
            }, $days);
            $rows[] = ['name' => $c['name'], 'division_id' => $c['division_id'], 'values' => $values];
            $known = array_values(array_filter($values, fn (?float $v): bool => $v !== null));
            $down = count($known) >= 3;
            for ($i = 1; $i < count($known); $i++) {
                $down = $down && $known[$i] < $known[$i - 1];
            }
            $last = $known === [] ? null : $known[count($known) - 1];
            if ($down && $last !== null && ($falling === null || $last < $falling[2])) {
                $falling = [$c['name'], $known[0], $last];
            }
        }
        $finding = $falling !== null ? "{$falling[0]} has fallen this week: {$falling[1]}% to {$falling[2]}%." : null;

        return Panels::make('attendance_classes', $view, 30, 'half', 'heat', 'This week · each class', 'Attendance by class', $finding,
            ['columns' => array_map(fn (string $d): string => CarbonImmutable::parse($d)->format('D'), $days), 'rule' => self::RULE, 'rows' => $rows]);
    }

    /**
     * @param  list<array{class_id: string, day: string, reason: string|null}>  $absences
     * @return Panel
     */
    private function reasons(array $absences, string $view): array
    {
        $counts = [];
        foreach ($absences as $a) {
            $name = $a['reason'] === null ? 'No reason given' : (self::REASONS[$a['reason']] ?? 'Other');
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);
        $total = array_sum($counts);
        $parts = [];
        foreach ($counts as $name => $n) {
            $parts[] = ['name' => (string) $name, 'value' => $n];
        }
        $none = $counts['No reason given'] ?? 0;
        $finding = $total === 0 ? 'No absences this term.' : 'No reason was given for '.(int) round($none / $total * 100).'% of absences.';

        return Panels::make('absence_reasons', $view, 50, 'half', 'segments', 'This term', 'Reasons for absence', $finding, ['unit' => 'count', 'parts' => $parts]);
    }

    /**
     * @param  array<string, array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $classes
     * @param  list<array{learner_id: string, class_id: string, present: int, marked: int}>  $learners
     * @param  array<int, array{learner_id: string, class_id: string, present: int, marked: int}>  $low
     * @return Panel
     */
    private function low(DashboardScope $scope, array $classes, array $learners, array $low, string $view): array
    {
        $groupOf = fn (string $class): string => $scope->school ? ($classes[$class]['division_id'] ?? '') : $class;
        $all = [];
        $few = [];
        foreach ($learners as $l) {
            $g = $groupOf($l['class_id']);
            $all[$g] = ($all[$g] ?? 0) + 1;
        }
        foreach ($low as $l) {
            $g = $groupOf($l['class_id']);
            $few[$g] = ($few[$g] ?? 0) + 1;
        }
        $rows = [];
        foreach ($all as $g => $n) {
            $count = $few[$g] ?? 0;
            if (! $scope->school && $count === 0) {
                continue;
            }
            $rows[] = ['name' => $scope->school ? $scope->name($g) : ($classes[$g]['name'] ?? ''), 'division_id' => $scope->school ? $g : ($classes[$g]['division_id'] ?? null),
                'value' => $count, 'whole' => null, 'note' => $count.' · '.(int) round($count / $n * 100).'% of learners', 'tone' => 'plain'];
        }
        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $finding = $rows === [] || $rows[0]['value'] === 0 ? 'No learner is below '.self::RULE.'% this term.' : "{$rows[0]['name']} has the most.";

        return Panels::make('attendance_low', $view, 60, 'half', 'bars', 'Below '.self::RULE.'% · this term', 'Learners below the attendance rule', $finding, ['unit' => 'count', 'rows' => $rows]);
    }

    /** @param  list<string>  $seen */
    private function termStart(array $seen, string $day): string
    {
        $starts = [];
        foreach ($seen as $id) {
            $p = $this->teaching->periodAt($id, $day);
            if ($p !== null) {
                $starts[] = $p['starts_on'];
            }
        }

        return $starts === [] ? CarbonImmutable::parse($day)->subWeeks(12)->toDateString() : min($starts);
    }

    /**
     * The classes of the scope whose morning register is not taken on a school day.
     *
     * @param  array<string, array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>  $classes
     * @return list<string> class names
     */
    public function notTaken(array $classes, string $day): array
    {
        $taken = DB::table('attendance_registers')->where('day', $day)->where('kind', SubmitRegister::KIND)->whereIn('class_id', array_keys($classes) ?: ['00000000-0000-0000-0000-000000000000'])->pluck('class_id')->all();
        $out = [];
        foreach ($classes as $id => $c) {
            if (! in_array($id, $taken, true) && $this->days->isSchoolDay($c['division_id'], $day)) {
                $out[] = $c['name'];
            }
        }

        return $out;
    }
}
