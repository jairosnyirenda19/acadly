<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lectures in the student portal (D-102): the student's lectures today and
 * this week, only for the courses they hold a place in, their attendance by
 * course, and their attendance phone.
 *
 * @phpstan-import-type Lecture from LectureDays
 *
 * @phpstan-type Summary array{rate: int|null, held: int, missed: int, courses: list<array{code: string, title: string, held: int, missed: int, late: int, rate: int|null}>, marks: list<array{day: string, code: string, kind: string, mark: string, source: string, reason: string|null, by: string|null, was: string|null}>}
 * @phpstan-type StudentLecture array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, kind: string, code: string, title: string, state: string, lecturer: string, cover: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, mark: string|null, scan: array{open: bool, until: string}|null}
 */
final class StudentLectures
{
    public function __construct(
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly LectureViews $views,
        private readonly Clock $clock,
    ) {}

    /**
     * Today's lectures, the one open for scanning now, and the phone.
     *
     * @return array<string, mixed>
     */
    public function today(User $user): array
    {
        $me = $this->me($user);
        $today = $this->clock->today();
        $lectures = $this->lectures($me, $today);
        $open = null;
        foreach ($lectures as $l) {
            if ($l['scan'] !== null && $l['scan']['open'] && $open === null) {
                $open = $l;
            }
        }
        $phone = DB::table('lecture_phones')->where('learner_id', $me['learner_id'])->whereNull('reset_at')->first(['credential_id', 'created_at']);

        return ['day' => $today, 'lectures' => $lectures, 'open' => $open,
            'phone' => ['set' => $phone !== null, 'credential_id' => $phone === null ? null : Row::text($phone, 'credential_id'),
                'set_on' => $phone === null ? null : substr(CarbonImmutable::parse(Row::text($phone, 'created_at'))->toDateString(), 0, 10), 'rp_id' => Passkeys::rpId()],
            'location' => DB::table('lecture_areas')->where('division_id', $me['division_id'])->whereNull('retired_at')->exists()];
    }

    /**
     * The lectures of a week, Monday to Saturday.
     *
     * @return array{from: string, days: list<array{day: string, lectures: list<array<string, mixed>>}>}
     */
    public function week(User $user, ?string $from): array
    {
        $me = $this->me($user);
        $monday = CarbonImmutable::parse($from ?? $this->clock->today())->startOfWeek(CarbonImmutable::MONDAY);
        $days = [];
        foreach (range(0, 5) as $i) {
            $d = $monday->addDays($i)->toDateString();
            $days[] = ['day' => $d, 'lectures' => $this->lectures($me, $d)];
        }

        return ['from' => $monday->toDateString(), 'days' => $days];
    }

    /**
     * The student's attendance this semester, by course, and the lectures missed or marked by hand.
     *
     * @return array<string, mixed>
     */
    public function attendance(User $user): array
    {
        $me = $this->me($user);
        $period = $this->days->period($me['division_id'], $this->clock->today());
        if ($period === null) {
            return ['semester' => null, 'rate' => null, 'held' => 0, 'missed' => 0, 'courses' => [], 'marks' => []];
        }

        return ['semester' => $period['name'], ...self::summary($me['learner_id'], $me['division_id'], $period['id'], $this->teaching)];
    }

    /**
     * A student's attendance in a semester, ready to show.
     *
     * @return Summary
     */
    public static function summary(string $learnerId, string $divisionId, string $periodId, CourseTeaching $teaching): array
    {
        $titles = [];
        foreach ($teaching->offerings($divisionId, $periodId) as $o) {
            $titles[$o['id']] = $o;
        }
        $m = Marks::ofLearner($learnerId, $periodId);
        $held = array_sum(array_column($m['rates'], 'held'));
        $attended = array_sum(array_column($m['rates'], 'attended'));
        $courses = [];
        foreach ($m['rates'] as $r) {
            $courses[] = ['code' => $titles[$r['offering_id']]['code'] ?? '', 'title' => $titles[$r['offering_id']]['title'] ?? '', 'held' => $r['held'], 'missed' => $r['missed'],
                'late' => $r['late'], 'rate' => Marks::percent($r['attended'], $r['held'])];
        }
        usort($courses, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        $kinds = DB::table('lecture_slots')->whereIn('id', array_column($m['missed'], 'slot_id') ?: ['00000000-0000-0000-0000-000000000000'])->pluck('kind', 'id')->all();
        $marks = [];
        foreach ($m['missed'] as $x) {
            $marks[] = ['day' => $x['held_on'], 'code' => $titles[$x['offering_id']]['code'] ?? '', 'kind' => is_string($kinds[$x['slot_id']] ?? null) ? $kinds[$x['slot_id']] : '',
                'mark' => $x['mark'], 'source' => $x['source'], 'reason' => $x['reason'], 'by' => $x['by'], 'was' => $x['was']];
        }

        return ['rate' => Marks::percent($attended, $held), 'held' => $held, 'missed' => $held - $attended, 'courses' => $courses, 'marks' => $marks];
    }

    /**
     * The student's lectures on a day, with their own mark where attendance was taken.
     *
     * @param  array{learner_id: string, division_id: string}  $me
     * @return list<StudentLecture>
     */
    private function lectures(array $me, string $day): array
    {
        $period = $this->days->period($me['division_id'], $day);
        if ($period === null) {
            return [];
        }
        $held = $this->teaching->offeringsOf($me['learner_id'], $period['id']);
        $offerings = $this->views->offeringsOn($me['division_id'], $day);
        $now = $this->clock->now();
        $out = [];
        foreach ($this->days->on([$me['division_id']], $day) as $l) {
            if (! in_array($l['offering_id'], $held, true)) {
                continue;
            }
            $row = $this->views->row($l, $offerings);
            $s = DB::table('lecture_sessions')->where('slot_id', $l['slot_id'])->where('day', $l['day'])->first(['id', 'scan_until', 'closed_at']);
            $mark = null;
            $scan = null;
            if ($s !== null) {
                $m = Marks::latest([Row::text($s, 'id')], $me['learner_id'])[Row::text($s, 'id').'|'.$me['learner_id']] ?? null;
                $mark = $m === null ? null : Row::text($m, 'mark');
                $until = CarbonImmutable::parse(Row::text($s, 'scan_until'));
                $scan = ['open' => ($s->closed_at ?? null) === null && $now->lessThan($until) && $mark === null && $l['held_on'] === $day, 'until' => $until->toIso8601String()];
            }
            $out[] = ['slot_id' => $row['slot_id'], 'day' => $row['day'], 'held_on' => $row['held_on'], 'starts' => $row['starts'], 'ends' => $row['ends'], 'room' => $row['room'],
                'kind' => $row['kind'], 'code' => $row['code'], 'title' => $row['title'], 'state' => $row['state'], 'lecturer' => $row['lecturer'], 'cover' => $row['cover'],
                'moved' => $row['moved'], 'mark' => $mark, 'scan' => $scan];
        }

        return $out;
    }

    /** @return array{learner_id: string, division_id: string} */
    private function me(User $user): array
    {
        return $this->teaching->studentOf($user->id) ?? throw new NotFoundHttpException;
    }
}
