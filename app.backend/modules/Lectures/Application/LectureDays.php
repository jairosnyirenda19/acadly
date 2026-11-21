<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The lectures of a day (D-102): the timetable's lectures that fall on it,
 * with what happened to each (cannot come, asked, covered, cancelled, moved
 * away), and the lectures moved onto it. The changes are rows; the latest
 * says where a lecture stands.
 *
 * @phpstan-type Lecture array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room_id: string, room: string, seats: int, offering_id: string, period_id: string, division_id: string, kind: string, lecturer_id: string, teacher_id: string, state: string, asked_id: string|null, cover_id: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null}
 */
final class LectureDays
{
    public const array KINDS = ['lecture', 'tutorial', 'practical'];

    public function __construct(private readonly AcademicCalendar $calendar) {}

    /**
     * The semester of a division that contains a day.
     *
     * @return array{id: string, name: string, starts_on: string, ends_on: string}|null
     */
    public function period(string $divisionId, string $day): ?array
    {
        foreach ($this->calendar->currentYear($day)['terms'][$divisionId] ?? [] as $term) {
            if ($term['starts_on'] <= $day && $term['ends_on'] >= $day) {
                return $term;
            }
        }

        return null;
    }

    /**
     * The semesters of the year that contains a day, for a division.
     *
     * @return list<array{id: string, name: string, starts_on: string, ends_on: string}>
     */
    public function periods(string $divisionId, string $day): array
    {
        return $this->calendar->currentYear($day)['terms'][$divisionId] ?? [];
    }

    public static function published(string $periodId): bool
    {
        return DB::table('lecture_timetables')->where('period_id', $periodId)->exists();
    }

    /**
     * The lectures of the divisions on a day, by time. Only a published timetable counts.
     *
     * @param  list<string>  $divisionIds
     * @return list<Lecture>
     */
    public function on(array $divisionIds, string $day): array
    {
        $out = [];
        $weekday = CarbonImmutable::parse($day)->dayOfWeekIso;
        $periods = [];
        foreach ($divisionIds as $d) {
            $p = $this->period($d, $day);
            if ($p !== null && self::published($p['id'])) {
                $periods[] = $p['id'];
            }
        }
        $slots = $periods === [] ? [] : $this->slots(DB::table('lecture_slots as s')->whereIn('s.period_id', $periods)->where('s.weekday', $weekday)->whereNull('s.retired_at'));
        $changes = $this->changes(array_keys($slots), [$day]);
        foreach ($slots as $id => $s) {
            $out[] = self::fold($s, $day, $changes[$id.'|'.$day] ?? []);
        }

        // Lectures moved onto this day from another.
        $moved = DB::table('lecture_changes')->whereIn('division_id', $divisionIds ?: [''])->where('kind', 'move')->where('moved_day', $day)->get(['slot_id', 'day']);
        if ($moved->isNotEmpty()) {
            $ids = array_values(array_unique(array_map(fn (object $m): string => Row::text($m, 'slot_id'), $moved->all())));
            $more = $this->slots(DB::table('lecture_slots as s')->whereIn('s.id', $ids));
            $days = array_values(array_unique(array_map(fn (object $m): string => Row::text($m, 'day'), $moved->all())));
            $all = $this->changes($ids, $days);
            foreach ($moved as $m) {
                $s = $more[Row::text($m, 'slot_id')] ?? null;
                $l = $s === null ? null : self::fold($s, Row::text($m, 'day'), $all[Row::text($m, 'slot_id').'|'.Row::text($m, 'day')] ?? []);
                if ($l !== null && $l['state'] !== 'cancelled' && $l['moved'] !== null && $l['moved']['day'] === $day && $l['day'] !== $day) {
                    $out[] = $l;
                }
            }
        }
        usort($out, fn (array $a, array $b): int => [$a['starts'], $a['slot_id']] <=> [$b['starts'], $b['slot_id']]);

        return $out;
    }

    /**
     * One lecture on its timetable day, or null when the slot is not on that day.
     *
     * @return Lecture|null
     */
    public function one(string $slotId, string $day): ?array
    {
        $s = $this->slots(DB::table('lecture_slots as s')->where('s.id', $slotId))[$slotId] ?? null;
        if ($s === null || CarbonImmutable::parse($day)->dayOfWeekIso !== $s['weekday']) {
            return null;
        }
        $p = $this->period($s['division_id'], $day);
        if ($p === null || $p['id'] !== $s['period_id']) {
            return null;
        }

        return self::fold($s, $day, $this->changes([$slotId], [$day])[$slotId.'|'.$day] ?? []);
    }

    /**
     * Whether the lecture is given on this day: not cancelled, not moved away.
     *
     * @param  Lecture  $l
     */
    public static function given(array $l, string $on): bool
    {
        return $l['state'] !== 'cancelled' && $l['held_on'] === $on;
    }

    /**
     * @return array<string, array{id: string, division_id: string, period_id: string, offering_id: string, kind: string, weekday: int, starts: string, ends: string, room_id: string, room: string, seats: int, lecturer_id: string}>
     */
    private function slots(Builder $q): array
    {
        $out = [];
        foreach ($q->join('lecture_rooms as r', 'r.id', '=', 's.room_id')->get(['s.id', 's.division_id', 's.period_id', 's.offering_id', 's.kind', 's.weekday', 's.starts', 's.ends', 's.room_id',
            'r.code as room', 'r.seats', 's.lecturer_id']) as $s) {
            $out[Row::text($s, 'id')] = ['id' => Row::text($s, 'id'), 'division_id' => Row::text($s, 'division_id'), 'period_id' => Row::text($s, 'period_id'),
                'offering_id' => Row::text($s, 'offering_id'), 'kind' => Row::text($s, 'kind'), 'weekday' => Row::int($s, 'weekday'), 'starts' => Row::text($s, 'starts'),
                'ends' => Row::text($s, 'ends'), 'room_id' => Row::text($s, 'room_id'), 'room' => Row::text($s, 'room'), 'seats' => Row::int($s, 'seats'), 'lecturer_id' => Row::text($s, 'lecturer_id')];
        }

        return $out;
    }

    /**
     * The changes of the slots on the days, oldest first, by "slot|day".
     *
     * @param  list<string>  $slotIds
     * @param  list<string>  $days
     * @return array<string, list<object>>
     */
    public function changes(array $slotIds, array $days): array
    {
        $out = [];
        if ($slotIds === [] || $days === []) {
            return $out;
        }
        foreach (DB::table('lecture_changes as c')->leftJoin('lecture_rooms as r', 'r.id', '=', 'c.moved_room_id')->whereIn('c.slot_id', $slotIds)->whereIn('c.day', $days)
            ->orderBy('c.created_at')->orderBy('c.id')->get(['c.*', 'r.code as moved_room']) as $c) {
            $out[Row::text($c, 'slot_id').'|'.substr(Row::text($c, 'day'), 0, 10)][] = $c;
        }

        return $out;
    }

    /**
     * Where a lecture stands after its changes.
     *
     * @param  array{id: string, division_id: string, period_id: string, offering_id: string, kind: string, weekday: int, starts: string, ends: string, room_id: string, room: string, seats: int, lecturer_id: string}  $s
     * @param  list<object>  $changes
     * @return Lecture
     */
    public static function fold(array $s, string $day, array $changes): array
    {
        $l = ['slot_id' => $s['id'], 'day' => $day, 'held_on' => $day, 'starts' => $s['starts'], 'ends' => $s['ends'], 'room_id' => $s['room_id'], 'room' => $s['room'], 'seats' => $s['seats'],
            'offering_id' => $s['offering_id'], 'period_id' => $s['period_id'], 'division_id' => $s['division_id'], 'kind' => $s['kind'], 'lecturer_id' => $s['lecturer_id'],
            'teacher_id' => $s['lecturer_id'], 'state' => 'on', 'asked_id' => null, 'cover_id' => null, 'reason' => null, 'moved' => null];
        foreach ($changes as $c) {
            $user = Row::maybeText($c, 'user_id');
            $reason = Row::maybeText($c, 'reason');
            switch (Row::text($c, 'kind')) {
                case 'away':
                    $l = [...$l, 'state' => 'away', 'reason' => $reason, 'asked_id' => null, 'cover_id' => null, 'teacher_id' => $s['lecturer_id']];
                    break;
                case 'ask':
                    $l = [...$l, 'state' => 'asked', 'asked_id' => $user];
                    break;
                case 'decline':
                    $l = [...$l, 'state' => 'away', 'asked_id' => null];
                    break;
                case 'accept':
                case 'cover':
                    $l = [...$l, 'state' => 'covered', 'cover_id' => $user, 'asked_id' => null, 'teacher_id' => $user ?? $s['lecturer_id']];
                    break;
                case 'cancel':
                    $l = [...$l, 'state' => 'cancelled', 'reason' => $reason];
                    break;
                case 'move':
                    $movedDay = substr(Row::text($c, 'moved_day'), 0, 10);
                    $room = Row::maybeText($c, 'moved_room_id') ?? $s['room_id'];
                    $l = [...$l, 'state' => 'moved', 'reason' => $reason, 'held_on' => $movedDay, 'starts' => Row::text($c, 'moved_starts'), 'ends' => Row::text($c, 'moved_ends'),
                        'room_id' => $room, 'room' => Row::maybeText($c, 'moved_room') ?? $s['room'],
                        'moved' => ['day' => $movedDay, 'starts' => Row::text($c, 'moved_starts'), 'ends' => Row::text($c, 'moved_ends'), 'room' => Row::maybeText($c, 'moved_room') ?? $s['room']]];
                    break;
            }
        }

        return $l;
    }
}
