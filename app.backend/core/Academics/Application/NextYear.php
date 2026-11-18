<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Institution\Contracts\CampusView;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Carbon\CarbonImmutable;

/**
 * The school year after this one (D-088), set up before this one ends: its
 * dates, the terms of each level and the classes of each level at each
 * campus. Until it is saved, it is proposed as a copy of this year, one year
 * on.
 */
final class NextYear
{
    public function __construct(
        private readonly Calendar $calendar,
        private readonly SchoolDirectory $school,
    ) {}

    /** @return array{id: string, name: string, starts_on: string, ends_on: string}|null the year that starts after this one ends */
    public function find(): ?array
    {
        return $this->calendar->nextYear();
    }

    /**
     * This year, and the next year as saved, or as proposed from this one.
     *
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $current = $this->calendar->currentYear();

        if ($current === null) {
            return ['current' => null, 'next' => null];
        }

        $saved = $this->find();
        $next = $saved !== null ? $this->calendar->currentYear($saved['starts_on']) : null;
        $on = fn (string $day): string => CarbonImmutable::parse($day)->addYear()->toDateString();
        $divisions = [];
        $campuses = [];

        foreach ($this->school->divisionViews() as $division) {
            $divisions[$division->id] = $division;
        }

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus;
        }

        $terms = [];

        foreach ($divisions as $division) {
            $source = $next !== null ? ($next['terms'][$division->id] ?? []) : array_map(fn (array $t): array => [...$t, 'starts_on' => $on($t['starts_on']), 'ends_on' => $on($t['ends_on'])],
                $current['terms'][$division->id] ?? []);
            $terms[] = ['division_id' => $division->id, 'division' => $division->name,
                'terms' => array_map(fn (array $t): array => ['name' => $t['name'], 'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on']], $source)];
        }

        $classes = [];

        foreach ($this->school->offerings() as $offering) {
            $division = $divisions[$offering['division_id']] ?? null;
            $campus = $campuses[$offering['campus_id']] ?? null;

            if (! $division instanceof DivisionView || ! $campus instanceof CampusView) {
                continue;
            }

            $now = $this->calendar->classesOf($current['id'], $division->id, $campus->id);
            $then = $saved !== null ? $this->calendar->classesOf($saved['id'], $division->id, $campus->id) : $now;
            $classes[] = ['division_id' => $division->id, 'division' => $division->name, 'campus_id' => $campus->id, 'campus' => $campus->name,
                'levels' => array_map(fn (array $level): array => [
                    'code' => $level['code'], 'label' => $level['label'],
                    'now' => array_values(array_filter($now[$level['code']] ?? [], 'is_string')),
                    'next' => array_values(array_filter($then[$level['code']] ?? [], 'is_string')),
                ], $division->profile->levels)];
        }

        return [
            'current' => ['id' => $current['id'], 'name' => $current['name'], 'starts_on' => $current['starts_on'], 'ends_on' => $current['ends_on']],
            'next' => [
                'saved' => $saved !== null,
                'id' => $saved['id'] ?? null,
                'name' => $saved['name'] ?? self::nameAfter($current['name']),
                'starts_on' => $saved['starts_on'] ?? $on($current['starts_on']),
                'ends_on' => $saved['ends_on'] ?? $on($current['ends_on']),
                'terms' => $terms,
                'classes' => $classes,
            ],
        ];
    }

    /** "2026/27" becomes "2027/28"; "2026" becomes "2027"; any other name is left for the office to write. */
    public static function nameAfter(string $name): string
    {
        if (preg_match('/^(\d{4})\/(\d{2})$/', $name, $m) === 1) {
            $start = (int) $m[1] + 1;

            return $start.'/'.str_pad((string) (($start + 1) % 100), 2, '0', STR_PAD_LEFT);
        }

        return preg_match('/^\d{4}$/', $name) === 1 ? (string) ((int) $name + 1) : '';
    }
}
