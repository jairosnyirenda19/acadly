<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads bell times and class timetables (the approved Timetable screens).
 *
 * @phpstan-import-type Bell from Timetable
 * @phpstan-import-type Lesson from Timetable
 */
final class Timetables implements Timetable
{
    /** @var array<string, list<array{id: string, name: string, weekdays: list<int>, periods: list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}>}>> */
    private array $plans = [];

    public function bells(string $divisionId, string $campusId, string $day): array
    {
        $weekday = self::weekday($day);

        foreach ($this->plansOf($divisionId, $campusId) as $plan) {
            if (in_array($weekday, $plan['weekdays'], true)) {
                return self::numbered($plan['periods']);
            }
        }

        return [];
    }

    /**
     * The bell plans of a level at a campus, in order.
     *
     * @return list<array{id: string, name: string, weekdays: list<int>, periods: list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}>}>
     */
    public function plansOf(string $divisionId, string $campusId): array
    {
        $key = $divisionId.':'.$campusId;

        if (! isset($this->plans[$key])) {
            $plans = [];

            foreach (DB::table('academic_bell_plans')->where('division_id', $divisionId)->where('campus_id', $campusId)->orderBy('position')->get() as $p) {
                $plans[] = [
                    'id' => Row::text($p, 'id'),
                    'name' => Row::text($p, 'name'),
                    'weekdays' => self::ints(json_decode(Row::text($p, 'weekdays'), true)),
                    'periods' => self::periods(json_decode(Row::text($p, 'periods'), true)),
                ];
            }

            $this->plans[$key] = $plans;
        }

        return $this->plans[$key];
    }

    public function forget(): void
    {
        $this->plans = [];
    }

    public function lessonsOn(array $classIds, string $day): array
    {
        return $classIds === [] ? [] : $this->lessons(fn (Builder $q): Builder => $q->whereIn('t.class_id', $classIds), $day);
    }

    public function lessonsOf(string $userId, string $day): array
    {
        return $this->lessons(fn (Builder $q): Builder => $q->where('t.user_id', $userId), $day);
    }

    public function lesson(string $slotId, string $day): ?array
    {
        if (! Str::isUuid($slotId)) {
            return null;
        }

        return $this->lessons(fn (Builder $q): Builder => $q->where('t.id', $slotId), $day)[0] ?? null;
    }

    /**
     * @param  callable(Builder): Builder  $which
     * @return list<Lesson>
     */
    private function lessons(callable $which, string $day): array
    {
        $rows = $which(DB::table('academic_timetable as t'))
            ->join('academic_classes as c', 'c.id', '=', 't.class_id')
            ->join('academic_subjects as s', 's.id', '=', 't.subject_id')
            ->where('t.weekday', self::weekday($day))
            ->where('t.starts_on', '<=', $day)
            ->where(fn (Builder $q): Builder => $q->whereNull('t.ends_on')->orWhere('t.ends_on', '>=', $day))
            ->orderBy('t.period')
            ->get(['t.id', 't.class_id', 't.period', 't.subject_id', 't.user_id', 's.name as subject', 's.short', 'c.division_id', 'c.campus_id']);

        $out = [];

        foreach ($rows as $row) {
            $period = (int) Row::int($row, 'period');
            $bell = null;

            foreach ($this->bells(Row::text($row, 'division_id'), Row::text($row, 'campus_id'), $day) as $b) {
                if ($b['number'] === $period) {
                    $bell = $b;
                }
            }

            // A period the bell times of that day do not have is not a lesson that day.
            if ($bell === null) {
                continue;
            }

            $out[] = [
                'slot_id' => Row::text($row, 'id'),
                'class_id' => Row::text($row, 'class_id'),
                'day' => $day,
                'period' => $period,
                'name' => $bell['name'],
                'starts' => $bell['starts'],
                'ends' => $bell['ends'],
                'subject_id' => Row::text($row, 'subject_id'),
                'subject' => Row::text($row, 'subject'),
                'subject_short' => Row::text($row, 'short'),
                'teacher_id' => Row::maybeText($row, 'user_id'),
            ];
        }

        usort($out, fn (array $a, array $b): int => [$a['starts'], $a['class_id']] <=> [$b['starts'], $b['class_id']]);

        return $out;
    }

    /**
     * @param  list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}>  $periods
     * @return list<Bell>
     */
    public static function numbered(array $periods): array
    {
        $n = 0;
        $out = [];

        foreach ($periods as $p) {
            $out[] = ['number' => $p['kind'] === 'lesson' ? ++$n : null, ...$p];
        }

        return $out;
    }

    /** Monday is 1, Sunday 7. */
    public static function weekday(string $day): int
    {
        return CarbonImmutable::parse($day)->dayOfWeekIso;
    }

    /** @return list<int> */
    private static function ints(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(intval(...), array_filter($value, is_numeric(...)))) : [];
    }

    /** @return list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}> */
    private static function periods(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $p) {
            if (is_array($p) && is_string($p['name'] ?? null) && is_string($p['starts'] ?? null) && is_string($p['ends'] ?? null)) {
                $out[] = ['name' => $p['name'], 'starts' => $p['starts'], 'ends' => $p['ends'], 'kind' => ($p['kind'] ?? null) === 'break' ? 'break' : 'lesson'];
            }
        }

        return $out;
    }
}
