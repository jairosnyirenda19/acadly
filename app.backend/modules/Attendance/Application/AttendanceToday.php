<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance's part of Today (D-100): the morning register of the person's own
 * class and the lesson happening now or next, the registers they still owe,
 * the reasons families gave and the absences no one has explained; for those
 * who see every register of a division, the classes not yet taken.
 *
 * @phpstan-import-type Part from TodayItems
 */
final class AttendanceToday implements TodayItems
{
    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly Timetable $timetable,
        private readonly EnrolmentDirectory $learners,
        private readonly AttendanceRates $rates,
        private readonly AttendanceDashboard $dashboard,
        private readonly AbsenceReasons $reasons,
        private readonly SchoolDays $days,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function today(DashboardScope $scope): array
    {
        $day = $scope->day;
        $now = Lessons::clock($this->clock);
        $viewer = $scope->viewer;
        $part = ['now' => [], 'items' => []];
        $classes = $this->rates->classes($scope->ids(), $day);

        // The person's own class: the morning register.
        $own = array_values(array_filter($classes, fn (array $c): bool => $this->calendar->classTeacherId($c['id']) === $viewer->id));
        foreach ($own as $i => $c) {
            if (! $this->days->isSchoolDay($c['division_id'], $day)) {
                continue;
            }
            $taken = DB::table('attendance_registers')->where('class_id', $c['id'])->where('day', $day)->where('kind', SubmitRegister::KIND)->value('submitted_at');
            $count = count($this->learners->learnersIn($c['id'], $day));
            $part['now'][] = ['key' => 'morning:'.$c['id'], 'order' => $taken === null ? 10 + $i : 40 + $i, 'label' => 'Morning register · your class', 'title' => $c['name'], 'sub' => "{$count} learners",
                'state' => is_string($taken) ? ['tone' => 'done', 'text' => 'Taken '.CarbonImmutable::parse($taken)->setTimezone(config()->string('acadly.timezone', 'UTC'))->format('H:i')] : ['tone' => 'attention', 'text' => 'Not taken'],
                'action' => is_string($taken) ? 'Open the register' : 'Take the register', 'href' => '/registers/'.$c['id']];
        }

        // The person's lessons: the one now or next, and the ones that ended without a register.
        // Only the lessons of classes in the place worked in (D-099): a Secondary lesson is not on a Primary Today.
        $lessons = array_values(array_filter($this->timetable->lessonsOf($viewer->id, $day), fn (array $l): bool => isset($classes[$l['class_id']])));
        $registered = DB::table('attendance_registers')->where('day', $day)->where('kind', SubmitLessonRegister::KIND)->whereIn('slot_id', array_column($lessons, 'slot_id') ?: ['00000000-0000-0000-0000-000000000000'])->pluck('slot_id')->all();
        $names = $this->calendar->classNames(array_values(array_unique(array_column($lessons, 'class_id'))));
        $owed = [];
        $next = null;
        foreach ($lessons as $l) {
            if (! $l['register']) {
                continue;
            }
            $done = in_array($l['slot_id'], $registered, true);
            if ($l['ends'] <= $now && ! $done) {
                $owed[] = ($names[$l['class_id']]['name'] ?? '').', '.$l['name'];
            }
            if ($next === null && $l['ends'] > $now) {
                $next = [$l, $done];
            }
        }
        if ($next !== null) {
            [$l, $done] = $next;
            $count = count($this->learners->learnersIn($l['class_id'], $day));
            $part['now'][] = ['key' => 'lesson:'.$l['slot_id'], 'order' => $l['starts'] <= $now ? 5 : 20, 'label' => ($l['starts'] <= $now ? 'Now' : 'Next').' · '.$l['name'].' · '.$l['starts'].'–'.$l['ends'],
                'title' => $names[$l['class_id']]['name'] ?? '', 'sub' => trim($l['subject'].' · '.$count.' learners', ' ·'),
                'state' => $done ? ['tone' => 'done', 'text' => 'Taken'] : ['tone' => 'attention', 'text' => 'Not taken'],
                'action' => $done ? 'Open the register' : 'Take the register', 'href' => '/registers/lesson/'.$l['slot_id']];
        }
        if ($owed !== []) {
            $part['items'][] = ['key' => 'lessons_owed', 'order' => 10, 'title' => 'Registers not taken', 'sub' => implode(' · ', array_slice($owed, 0, 3)), 'count' => '! '.count($owed), 'tone' => 'attention', 'href' => '/my-day'];
        }
        $teaches = $lessons !== [] || $own !== [];

        // Reasons families gave, and absences no one has explained, in the person's own classes.
        $ownIds = array_column($own, 'id');
        $toAccept = $this->reasons->toAccept($ownIds, $day);
        if ($toAccept !== []) {
            $part['items'][] = ['key' => 'reasons', 'order' => 20, 'title' => 'Reasons from families', 'sub' => count($toAccept) === 1 ? '1 absence explained, to accept' : count($toAccept).' absences explained, to accept',
                'count' => (string) count($toAccept), 'tone' => 'attention', 'href' => '/my-day'];
        }
        $unexplained = array_filter($this->rates->absences($ownIds, CarbonImmutable::parse($day)->subDays(6)->toDateString(), $day), fn (array $a): bool => $a['reason'] === null);
        if ($unexplained !== []) {
            $part['items'][] = ['key' => 'unexplained', 'order' => 25, 'title' => 'Absences to follow up', 'sub' => 'No reason given, in the last 7 days', 'count' => '! '.count($unexplained), 'tone' => 'attention',
                'href' => '/registers/'.$ownIds[0]];
        }

        // Those who see every register of a division: the classes not taken.
        $all = $scope->sees(AttendancePermission::AllClasses);
        if ($all !== [] && ! $scope->school) {
            $late = $this->dashboard->notTaken(array_filter($classes, fn (array $c): bool => in_array($c['division_id'], $all, true)), $day);
            if ($late !== []) {
                $part['items'][] = ['key' => 'registers_late', 'order' => 5, 'title' => 'Registers not taken', 'sub' => implode(', ', array_slice($late, 0, 4)).(count($late) > 4 ? ' and '.(count($late) - 4).' more' : ''),
                    'count' => '! '.count($late), 'tone' => 'attention', 'href' => '/registers'];
            }
        }

        // One small chart: the person's own class this week.
        if ($own !== []) {
            $c = $own[0];
            $monday = CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY);
            $days = array_map(fn (int $i): string => $monday->addDays($i)->toDateString(), range(0, 4));
            $daily = $this->rates->daily([$c['id']], $days[0], $days[4]);
            $values = array_map(function (string $d) use ($daily): ?float {
                foreach ($daily as $r) {
                    if ($r['day'] === $d) {
                        return Panels::percent($r['present'], $r['marked']);
                    }
                }

                return null;
            }, $days);
            $part['chart'] = ['kind' => 'columns', 'label' => $c['name'].' · in this week', 'unit' => 'percent', 'labels' => array_map(fn (string $d): string => CarbonImmutable::parse($d)->format('D'), $days), 'values' => $values, 'parts' => []];
        } elseif ($lessons !== []) {
            $total = count(array_filter($lessons, fn (array $l): bool => $l['register']));
            $done = count(array_intersect(array_column($lessons, 'slot_id'), array_filter($registered, 'is_string')));
            $part['chart'] = ['kind' => 'segments', 'label' => 'My registers · today · '.$total.' lessons', 'unit' => 'count', 'labels' => [], 'values' => [],
                'parts' => [['name' => 'Taken', 'value' => $done, 'tone' => 'done'], ['name' => 'Not taken', 'value' => count($owed), 'tone' => 'attention'], ['name' => 'Still to come', 'value' => max(0, $total - $done - count($owed)), 'tone' => 'plain']]];
        }

        if (! $teaches && $part['items'] === []) {
            return [];
        }

        return $part;
    }
}
