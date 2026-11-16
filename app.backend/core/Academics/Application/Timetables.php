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
 * Lesson periods are numbered 1, 2, … in the order of the day. An activity
 * for the whole level in the bell times is numbered from 101; it has no
 * timetable row, so each class's share of it is named by an id made from the
 * class, the weekday and the number.
 *
 * @phpstan-import-type Bell from Timetable
 * @phpstan-import-type Lesson from Timetable
 *
 * @phpstan-type Period array{name: string, starts: string, ends: string, kind: 'lesson'|'break'|'activity', register: bool}
 */
final class Timetables implements Timetable
{
    public const int FIRST_ACTIVITY = 101;

    /** @var array<string, list<array{id: string, name: string, weekdays: list<int>, periods: list<Period>}>> */
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
     * @return list<array{id: string, name: string, weekdays: list<int>, periods: list<Period>}>
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
        if ($classIds === []) {
            return [];
        }

        $lessons = [...$this->lessons(fn (Builder $q): Builder => $q->whereIn('t.class_id', $classIds), $day), ...$this->levelActivities($classIds, $day)];
        usort($lessons, fn (array $a, array $b): int => [$a['starts'], $a['class_id']] <=> [$b['starts'], $b['class_id']]);

        return $lessons;
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

        $row = $this->lessons(fn (Builder $q): Builder => $q->where('t.id', $slotId), $day)[0] ?? null;

        if ($row !== null || DB::table('academic_timetable')->where('id', $slotId)->exists()) {
            return $row;
        }

        // An activity for the whole level: find the class whose share has this id.
        $classes = DB::table('academic_classes')->whereNull('ended_at')->pluck('id')->all();

        foreach ($this->levelActivities(array_values(array_filter($classes, is_string(...))), $day) as $lesson) {
            if ($lesson['slot_id'] === $slotId) {
                return $lesson;
            }
        }

        return null;
    }

    /** The id of one class's share of an activity for the whole level. */
    public static function levelActivityId(string $classId, int $weekday, int $number): string
    {
        $h = md5("bell-activity|{$classId}|{$weekday}|{$number}");

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-8'.substr($h, 13, 3).'-'.dechex(8 | (hexdec($h[16]) & 3)).substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    /**
     * @param  list<string>  $classIds
     * @return list<Lesson>
     */
    private function levelActivities(array $classIds, string $day): array
    {
        $weekday = self::weekday($day);
        $out = [];

        foreach (DB::table('academic_classes')->whereIn('id', $classIds)->get(['id', 'division_id', 'campus_id']) as $class) {
            foreach ($this->bells(Row::text($class, 'division_id'), Row::text($class, 'campus_id'), $day) as $b) {
                if ($b['kind'] !== 'activity' || $b['number'] === null) {
                    continue;
                }

                $out[] = [
                    'slot_id' => self::levelActivityId(Row::text($class, 'id'), $weekday, $b['number']),
                    'class_id' => Row::text($class, 'id'),
                    'day' => $day,
                    'period' => $b['number'],
                    'name' => $b['name'],
                    'starts' => $b['starts'],
                    'ends' => $b['ends'],
                    'kind' => 'activity',
                    'whole_level' => true,
                    'subject_id' => null,
                    'subject' => $b['name'],
                    'subject_short' => '',
                    'teacher_id' => null,
                    'register' => $b['register'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  callable(Builder): Builder  $which
     * @return list<Lesson>
     */
    private function lessons(callable $which, string $day): array
    {
        $rows = $which(DB::table('academic_timetable as t'))
            ->join('academic_classes as c', 'c.id', '=', 't.class_id')
            ->leftJoin('academic_subjects as s', 's.id', '=', 't.subject_id')
            ->where('t.weekday', self::weekday($day))
            ->where('t.starts_on', '<=', $day)
            ->where(fn (Builder $q): Builder => $q->whereNull('t.ends_on')->orWhere('t.ends_on', '>=', $day))
            ->orderBy('t.period')
            ->get(['t.id', 't.class_id', 't.period', 't.subject_id', 't.activity', 't.user_id', 's.name as subject', 's.short', 'c.division_id', 'c.campus_id']);

        $out = [];

        foreach ($rows as $row) {
            $period = Row::int($row, 'period');
            $bell = null;

            foreach ($this->bells(Row::text($row, 'division_id'), Row::text($row, 'campus_id'), $day) as $b) {
                if ($b['number'] === $period && $b['kind'] === 'lesson') {
                    $bell = $b;
                }
            }

            // A period the bell times of that day do not have is not a lesson that day.
            if ($bell === null) {
                continue;
            }

            $activity = Row::maybeText($row, 'activity');
            $out[] = [
                'slot_id' => Row::text($row, 'id'),
                'class_id' => Row::text($row, 'class_id'),
                'day' => $day,
                'period' => $period,
                'name' => $bell['name'],
                'starts' => $bell['starts'],
                'ends' => $bell['ends'],
                'kind' => $activity !== null ? 'activity' : 'lesson',
                'whole_level' => false,
                'subject_id' => Row::maybeText($row, 'subject_id'),
                'subject' => $activity ?? Row::maybeText($row, 'subject') ?? '',
                'subject_short' => $activity !== null ? '' : (Row::maybeText($row, 'short') ?? ''),
                'teacher_id' => Row::maybeText($row, 'user_id'),
                'register' => $bell['register'],
            ];
        }

        usort($out, fn (array $a, array $b): int => [$a['starts'], $a['class_id']] <=> [$b['starts'], $b['class_id']]);

        return $out;
    }

    /**
     * Numbers the periods: lessons 1, 2, …; activities for the whole level from 101; breaks none.
     *
     * @param  list<Period>  $periods
     * @return list<Bell>
     */
    public static function numbered(array $periods): array
    {
        $lesson = 0;
        $activity = self::FIRST_ACTIVITY - 1;
        $out = [];

        foreach ($periods as $p) {
            $out[] = ['number' => match ($p['kind']) {
                'lesson' => ++$lesson,
                'activity' => ++$activity,
                'break' => null,
            }, ...$p];
        }

        return $out;
    }

    /**
     * Which periods need a register when the school chose none: the first after each break.
     *
     * @param  list<array{kind: 'lesson'|'break'|'activity', register?: bool|null}>  $periods
     * @return list<bool>
     */
    public static function afterBreaks(array $periods): array
    {
        $out = [];
        $previous = null;

        foreach ($periods as $p) {
            $out[] = $p['kind'] !== 'break' && $previous === 'break';
            $previous = $p['kind'];
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

    /** @return list<Period> */
    private static function periods(mixed $value): array
    {
        $out = [];
        $kinds = [];

        foreach (is_array($value) ? $value : [] as $p) {
            if (is_array($p) && is_string($p['name'] ?? null) && is_string($p['starts'] ?? null) && is_string($p['ends'] ?? null)) {
                $kind = match ($p['kind'] ?? null) {
                    'break' => 'break',
                    'activity' => 'activity',
                    default => 'lesson',
                };
                $out[] = ['name' => $p['name'], 'starts' => $p['starts'], 'ends' => $p['ends'], 'kind' => $kind, 'register' => is_bool($p['register'] ?? null) ? $p['register'] : null];
                $kinds[] = ['kind' => $kind];
            }
        }

        // Bell times saved before the register switch existed take the default.
        $defaults = self::afterBreaks($kinds);

        return array_map(fn (array $p, bool $d): array => [...$p, 'register' => $p['kind'] !== 'break' && ($p['register'] ?? $d)], $out, $defaults);
    }
}
