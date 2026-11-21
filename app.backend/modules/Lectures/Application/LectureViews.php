<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Lectures\Application\Actions\RoomActions;
use Acadly\Modules\Lectures\Application\Actions\SessionActions;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the console shows of Lectures (D-102): rooms, the timetable, cover,
 * the lecturer's day and one lecture with its attendance.
 *
 * @phpstan-import-type Lecture from LectureDays
 *
 * @phpstan-type LectureRow array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}
 */
final class LectureViews
{
    private const string NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly TimetableChecks $checks,
        private readonly Access $access,
        private readonly Clock $clock,
    ) {}

    /** @return array{rooms: list<array{id: string, code: string, name: string, seats: int, kind: string, retired: bool}>, areas: list<array{name: string, latitude: float, longitude: float, reach: int}>, scan_minutes: int} */
    public function rooms(string $divisionId): array
    {
        $rooms = [];
        foreach (DB::table('lecture_rooms')->where('division_id', $divisionId)->orderByRaw('retired_at is not null')->orderBy('code')->get() as $r) {
            $rooms[] = ['id' => Row::text($r, 'id'), 'code' => Row::text($r, 'code'), 'name' => Row::text($r, 'name'), 'seats' => Row::int($r, 'seats'), 'kind' => Row::text($r, 'kind'),
                'retired' => ($r->retired_at ?? null) !== null];
        }
        $areas = [];
        foreach (DB::table('lecture_areas')->where('division_id', $divisionId)->whereNull('retired_at')->orderBy('created_at')->get() as $a) {
            $areas[] = ['name' => Row::text($a, 'name'), 'latitude' => (float) Row::text($a, 'latitude'), 'longitude' => (float) Row::text($a, 'longitude'), 'reach' => Row::int($a, 'reach')];
        }

        return ['rooms' => $rooms, 'areas' => $areas, 'scan_minutes' => RoomActions::minutes($divisionId)];
    }

    /**
     * The timetable of a semester, with what is needed to change it.
     *
     * @return array<string, mixed>
     */
    public function timetable(User $viewer, string $divisionId, ?string $periodId): array
    {
        $today = $this->clock->today();
        $periods = $this->days->periods($divisionId, $today);
        $period = null;
        foreach ($periods as $p) {
            if ($p['id'] === $periodId || ($periodId === null && $period === null && $p['ends_on'] >= $today)) {
                $period = $p;
            }
        }
        $period ??= $periods[0] ?? null;
        if ($period === null) {
            return ['periods' => [], 'period' => null, 'published' => false, 'can_change' => false, 'rooms' => [], 'offerings' => [], 'cohorts' => [], 'staff' => [], 'slots' => [], 'checks' => []];
        }
        $offerings = $this->teaching->offerings($divisionId, $period['id']);
        $slots = [];
        foreach (DB::table('lecture_slots as s')->join('users as u', 'u.id', '=', 's.lecturer_id')->where('s.period_id', $period['id'])->where('s.division_id', $divisionId)
            ->whereNull('s.retired_at')->orderBy('s.weekday')->orderBy('s.starts')->get(['s.*', 'u.name as lecturer']) as $s) {
            $slots[] = ['id' => Row::text($s, 'id'), 'offering_id' => Row::text($s, 'offering_id'), 'kind' => Row::text($s, 'kind'), 'weekday' => Row::int($s, 'weekday'),
                'starts' => Row::text($s, 'starts'), 'ends' => Row::text($s, 'ends'), 'room_id' => Row::text($s, 'room_id'), 'lecturer_id' => Row::text($s, 'lecturer_id'),
                'lecturer' => Row::text($s, 'lecturer')];
        }
        $canChange = $this->access->allows($viewer, LecturesPermission::Timetable, ScopeRef::of(ScopeType::Division, $divisionId));

        return ['periods' => array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], $periods), 'period' => ['id' => $period['id'], 'name' => $period['name'],
            'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']], 'published' => LectureDays::published($period['id']), 'can_change' => $canChange,
            'rooms' => array_values(array_filter($this->rooms($divisionId)['rooms'], fn (array $r): bool => ! $r['retired'])),
            'offerings' => array_map(fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'group' => $o['group'], 'department' => $o['department'],
                'lecturers' => $o['lecturers'], 'taken' => $o['taken']], $offerings),
            'cohorts' => $this->teaching->cohorts($divisionId, $period['id']), 'staff' => $this->staff($divisionId, $offerings), 'slots' => $slots,
            'checks' => $canChange ? $this->checks->run($divisionId, $period['id']) : []];
    }

    /**
     * The lectures of a week that need cover or were changed, Monday to Saturday.
     *
     * @return array{week: string, can_arrange: bool, rows: list<array<string, mixed>>, counts: array{need: int, asked: int, covered: int, changed: int}}
     */
    public function cover(User $viewer, string $divisionId, ?string $week): array
    {
        $monday = CarbonImmutable::parse($week ?? $this->clock->today())->startOfWeek(CarbonImmutable::MONDAY);
        $rows = [];
        $counts = ['need' => 0, 'asked' => 0, 'covered' => 0, 'changed' => 0];
        $offerings = $this->offeringsOn($divisionId, $monday->toDateString());
        foreach (range(0, 5) as $i) {
            $day = $monday->addDays($i)->toDateString();
            foreach ($this->days->on([$divisionId], $day) as $l) {
                if ($l['state'] === 'on' || $l['day'] !== $day) {
                    continue;
                }
                $counts[match ($l['state']) {
                    'away' => 'need', 'asked' => 'asked', 'covered' => 'covered', default => 'changed'
                }]++;
                $rows[] = $this->row($l, $offerings);
            }
        }

        return ['week' => $monday->toDateString(), 'can_arrange' => $this->access->allows($viewer, LecturesPermission::Cover, ScopeRef::of(ScopeType::Division, $divisionId)),
            'rows' => $rows, 'counts' => $counts];
    }

    /**
     * The colleagues who could cover a lecture, free or busy at its time.
     *
     * @return list<array{id: string, name: string, free: bool}>
     */
    public function free(string $divisionId, string $slotId, string $day): array
    {
        $l = $this->days->one($slotId, $day);
        if ($l === null || $l['division_id'] !== $divisionId) {
            throw new NotFoundHttpException;
        }
        $busy = [];
        foreach ($this->days->on([$divisionId], $l['held_on']) as $o) {
            if (LectureDays::given($o, $l['held_on']) && $o['starts'] < $l['ends'] && $l['starts'] < $o['ends']) {
                $busy[$o['teacher_id']] = true;
            }
        }
        $out = [];
        foreach ($this->staff($divisionId, $this->teaching->offerings($divisionId, $l['period_id'])) as $s) {
            if ($s['id'] !== $l['lecturer_id']) {
                $out[] = [...$s, 'free' => ! isset($busy[$s['id']])];
            }
        }
        usort($out, fn (array $a, array $b): int => [! $a['free'], $a['name']] <=> [! $b['free'], $b['name']]);

        return $out;
    }

    /**
     * The person's lectures on a day, those asked of them, and their week ahead.
     *
     * @return array<string, mixed>
     */
    public function myDay(User $viewer, string $divisionId, ?string $day): array
    {
        $day ??= $this->clock->today();
        $offerings = $this->offeringsOn($divisionId, $day);
        $mine = fn (array $l): bool => $l['teacher_id'] === $viewer->id || $l['lecturer_id'] === $viewer->id || $l['asked_id'] === $viewer->id;
        $today = [];
        foreach ($this->days->on([$divisionId], $day) as $l) {
            if ($mine($l)) {
                $today[] = $this->row($l, $offerings);
            }
        }
        $asked = [];
        $ahead = [];
        $from = CarbonImmutable::parse($day);
        foreach (range(1, 13) as $i) {
            $d = $from->addDays($i)->toDateString();
            foreach ($this->days->on([$divisionId], $d) as $l) {
                if ($l['asked_id'] === $viewer->id && $l['day'] === $d) {
                    $asked[] = $this->row($l, $offerings);
                } elseif ($i <= 6 && $l['day'] === $d && ($l['teacher_id'] === $viewer->id || $l['lecturer_id'] === $viewer->id)) {
                    $ahead[] = $this->row($l, $offerings);
                }
            }
        }
        foreach ($today as $t) {
            if ($t['asked_id'] === $viewer->id) {
                $asked[] = $t;
            }
        }

        return ['day' => $day, 'now' => SessionActions::local($this->clock)->format('H:i'), 'today' => array_values(array_filter($today, fn (array $t): bool => $t['asked_id'] !== $viewer->id)),
            'asked' => $asked, 'ahead' => $ahead];
    }

    /**
     * One lecture on one day, with its attendance.
     *
     * @return array<string, mixed>
     */
    public function lecture(string $divisionId, string $slotId, string $day): array
    {
        $l = $this->days->one($slotId, $day);
        if ($l === null || $l['division_id'] !== $divisionId) {
            throw new NotFoundHttpException;
        }
        $row = $this->row($l, $this->offeringsOn($divisionId, $day));
        $s = DB::table('lecture_sessions')->where('slot_id', $slotId)->where('day', $day)->first();
        $students = $this->teaching->students($l['offering_id']);
        $marks = $s === null ? [] : Marks::latest([Row::text($s, 'id')]);
        $refused = [];
        if ($s !== null) {
            foreach (DB::table('lecture_refusals')->where('session_id', Row::text($s, 'id'))->orderBy('created_at')->get() as $r) {
                $refused[Row::text($r, 'learner_id')] = ['why' => Row::text($r, 'why'), 'at' => self::hm(Row::text($r, 'created_at'))];
            }
        }
        $list = [];
        foreach ($students as $st) {
            $m = $s === null ? null : ($marks[Row::text($s, 'id').'|'.$st['learner_id']] ?? null);
            $list[] = ['learner_id' => $st['learner_id'], 'name' => $st['name'], 'number' => $st['number'], 'mark' => $m === null ? null : Row::text($m, 'mark'),
                'source' => $m === null ? null : Row::text($m, 'source'), 'at' => $m === null ? null : self::hm(Row::text($m, 'created_at')),
                'reason' => $m === null ? null : Row::maybeText($m, 'reason'), 'refused' => $m === null || Row::text($m, 'mark') === 'absent' ? ($refused[$st['learner_id']] ?? null) : null];
        }
        $session = $s === null ? null : ['id' => Row::text($s, 'id'), 'scan_until' => CarbonImmutable::parse(Row::text($s, 'scan_until'))->toIso8601String(),
            'scanning' => ($s->closed_at ?? null) === null && $this->clock->now()->lessThan(CarbonImmutable::parse(Row::text($s, 'scan_until'))),
            'closed' => ($s->closed_at ?? null) !== null];

        return ['lecture' => $row, 'session' => $session, 'students' => $list,
            'can_open' => $s === null && LectureDays::given($l, $this->clock->today()) && $l['day'] <= $this->clock->today()];
    }

    /**
     * What the room screen shows: the code now, and how many have scanned in.
     *
     * @return array{code: string, changes_in: int, scanned: int, of: int, scan_until: string, open: bool, lecture: array<string, mixed>}
     */
    public function code(string $divisionId, string $sessionId): array
    {
        $s = Where::owned('lecture_sessions', $sessionId, $divisionId);
        $l = $this->days->one(Row::text($s, 'slot_id'), substr(Row::text($s, 'day'), 0, 10)) ?? throw new NotFoundHttpException;
        $now = $this->clock->now()->getTimestamp();
        $marks = Marks::latest([$sessionId]);
        $scanned = count(array_filter($marks, fn (object $m): bool => Row::text($m, 'mark') !== 'absent'));
        $open = ($s->closed_at ?? null) === null && $this->clock->now()->lessThan(CarbonImmutable::parse(Row::text($s, 'scan_until')));

        return ['code' => $open ? RoomCode::current($sessionId, Row::text($s, 'secret'), $now) : '', 'changes_in' => RoomCode::changesIn($now), 'scanned' => $scanned,
            'of' => count($this->teaching->students($l['offering_id'])), 'scan_until' => CarbonImmutable::parse(Row::text($s, 'scan_until'))->toIso8601String(), 'open' => $open,
            'lecture' => $this->row($l, $this->offeringsOn($divisionId, substr(Row::text($s, 'day'), 0, 10)))];
    }

    /**
     * A lecture as a row for the screens.
     *
     * @param  Lecture  $l
     * @param  array<string, array{code: string, title: string, department: string, taken: int}>  $offerings
     * @return LectureRow
     */
    public function row(array $l, array $offerings): array
    {
        $o = $offerings[$l['offering_id']] ?? ['code' => '', 'title' => '', 'department' => '', 'taken' => 0];
        $names = self::names(array_values(array_filter([$l['lecturer_id'], $l['cover_id'], $l['asked_id']])));
        $session = DB::table('lecture_sessions')->where('slot_id', $l['slot_id'])->where('day', $l['day'])->first(['id', 'closed_at', 'scan_until']);
        $taken = null;
        if ($session !== null) {
            $marks = Marks::latest([Row::text($session, 'id')]);
            $taken = ['present' => count(array_filter($marks, fn (object $m): bool => Row::text($m, 'mark') !== 'absent')), 'closed' => ($session->closed_at ?? null) !== null,
                'scanning' => ($session->closed_at ?? null) === null && $this->clock->now()->lessThan(CarbonImmutable::parse(Row::text($session, 'scan_until')))];
        }

        return ['slot_id' => $l['slot_id'], 'day' => $l['day'], 'held_on' => $l['held_on'], 'starts' => $l['starts'], 'ends' => $l['ends'], 'room' => $l['room'], 'seats' => $l['seats'],
            'kind' => $l['kind'], 'code' => $o['code'], 'title' => $o['title'], 'department' => $o['department'], 'students' => $o['taken'], 'state' => $l['state'],
            'lecturer_id' => $l['lecturer_id'], 'lecturer' => $names[$l['lecturer_id']] ?? '', 'cover' => $l['cover_id'] !== null ? ($names[$l['cover_id']] ?? '') : null,
            'asked_id' => $l['asked_id'], 'asked' => $l['asked_id'] !== null ? ($names[$l['asked_id']] ?? '') : null, 'reason' => $l['reason'], 'moved' => $l['moved'], 'taken' => $taken];
    }

    /**
     * The offerings of the semester that contains a day, by id.
     *
     * @return array<string, array{code: string, title: string, department: string, taken: int}>
     */
    public function offeringsOn(string $divisionId, string $day): array
    {
        $p = $this->days->period($divisionId, $day);
        $out = [];
        foreach ($p === null ? [] : $this->teaching->offerings($divisionId, $p['id']) as $o) {
            $out[$o['id']] = ['code' => $o['code'], 'title' => $o['title'], 'department' => $o['department'], 'taken' => $o['taken']];
        }

        return $out;
    }

    /**
     * Who may give lectures in the division: those who take attendance there, and the lecturers named on its offerings.
     *
     * @param  list<array{lecturers: list<array{id: string, name: string}>}>  $offerings
     * @return list<array{id: string, name: string}>
     */
    private function staff(string $divisionId, array $offerings): array
    {
        $ids = $this->access->holderIds(LecturesPermission::Teach, ScopeRef::of(ScopeType::Division, $divisionId));
        foreach ($offerings as $o) {
            foreach ($o['lecturers'] as $l) {
                $ids[] = $l['id'];
            }
        }
        $out = [];
        foreach (DB::table('users')->whereIn('id', array_values(array_unique($ids)) ?: [self::NONE])->where('status', 'active')->orderBy('name')->get(['id', 'name']) as $u) {
            $out[] = ['id' => Row::text($u, 'id'), 'name' => Row::text($u, 'name')];
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function names(array $ids): array
    {
        $out = [];
        foreach (DB::table('users')->whereIn('id', $ids ?: [self::NONE])->get(['id', 'name']) as $u) {
            $out[Row::text($u, 'id')] = Row::text($u, 'name');
        }

        return $out;
    }

    private static function hm(string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(SessionActions::zone())->format('H:i');
    }
}
