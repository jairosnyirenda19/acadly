<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Support\Facades\DB;

/**
 * Attendance counted for the dashboards (D-100), from the morning registers:
 * for each class and day, how many were marked and how many were here (any
 * mark but absent), from the mark in force of each learner.
 */
final class AttendanceRates
{
    private const string NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly AcademicCalendar $calendar) {}

    /**
     * The classes of the school year of the day, in the divisions given.
     *
     * @param  list<string>  $divisionIds
     * @return array<string, array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}> class id => class
     */
    public function classes(array $divisionIds, string $day): array
    {
        $year = $this->calendar->currentYear($day);
        $out = [];

        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $c) {
            if (in_array($c['division_id'], $divisionIds, true)) {
                $out[$c['id']] = $c;
            }
        }

        return $out;
    }

    /**
     * Present and marked for each class and day.
     *
     * @param  list<string>  $classIds
     * @return list<array{class_id: string, day: string, present: int, marked: int}>
     */
    public function daily(array $classIds, string $from, string $to): array
    {
        $rows = DB::select(
            'select r.class_id, r.day::text as day, count(*) as marked, sum(case when x.status <> ? then 1 else 0 end) as present
             from (select distinct on (m.register_id, m.learner_id) m.register_id, m.status
                   from attendance_marks m join attendance_registers r on r.id = m.register_id
                   where r.kind = ? and r.day between ? and ? and r.class_id = any(?::uuid[])
                   order by m.register_id, m.learner_id, m.received_at desc, m.id desc) x
             join attendance_registers r on r.id = x.register_id
             group by r.class_id, r.day',
            [Mark::Absent->value, SubmitRegister::KIND, $from, $to, self::array($classIds)],
        );
        $out = [];

        foreach ($rows as $r) {
            if (! is_object($r)) {
                continue;
            }
            $out[] = ['class_id' => Row::text($r, 'class_id'), 'day' => Row::text($r, 'day'), 'present' => Row::int($r, 'present'), 'marked' => Row::int($r, 'marked')];
        }

        return $out;
    }

    /**
     * Present and marked for each learner over the days, with the class they were marked in last.
     *
     * @param  list<string>  $classIds
     * @return list<array{learner_id: string, class_id: string, present: int, marked: int}>
     */
    public function byLearner(array $classIds, string $from, string $to): array
    {
        $rows = DB::select(
            'select x.learner_id, max(r.class_id::text) as class_id, count(*) as marked, sum(case when x.status <> ? then 1 else 0 end) as present
             from (select distinct on (m.register_id, m.learner_id) m.register_id, m.learner_id, m.status
                   from attendance_marks m join attendance_registers r on r.id = m.register_id
                   where r.kind = ? and r.day between ? and ? and r.class_id = any(?::uuid[])
                   order by m.register_id, m.learner_id, m.received_at desc, m.id desc) x
             join attendance_registers r on r.id = x.register_id
             group by x.learner_id',
            [Mark::Absent->value, SubmitRegister::KIND, $from, $to, self::array($classIds)],
        );
        $out = [];

        foreach ($rows as $r) {
            if (! is_object($r)) {
                continue;
            }
            $out[] = ['learner_id' => Row::text($r, 'learner_id'), 'class_id' => Row::text($r, 'class_id'), 'present' => Row::int($r, 'present'), 'marked' => Row::int($r, 'marked')];
        }

        return $out;
    }

    /**
     * Absences in force over the days, with the reason a family gave, if any.
     *
     * @param  list<string>  $classIds
     * @return list<array{class_id: string, day: string, reason: string|null}>
     */
    public function absences(array $classIds, string $from, string $to): array
    {
        $rows = DB::select(
            'select r.class_id, r.day::text as day,
                    (select a.kind from attendance_absence_reasons a where a.register_id = x.register_id and a.learner_id = x.learner_id order by a.given_at desc limit 1) as reason
             from (select distinct on (m.register_id, m.learner_id) m.register_id, m.learner_id, m.status
                   from attendance_marks m join attendance_registers r on r.id = m.register_id
                   where r.kind = ? and r.day between ? and ? and r.class_id = any(?::uuid[])
                   order by m.register_id, m.learner_id, m.received_at desc, m.id desc) x
             join attendance_registers r on r.id = x.register_id
             where x.status = ?',
            [SubmitRegister::KIND, $from, $to, self::array($classIds), Mark::Absent->value],
        );
        $out = [];

        foreach ($rows as $r) {
            if (! is_object($r)) {
                continue;
            }
            $out[] = ['class_id' => Row::text($r, 'class_id'), 'day' => Row::text($r, 'day'), 'reason' => Row::maybeText($r, 'reason')];
        }

        return $out;
    }

    /** @param  list<string>  $ids */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids === [] ? [self::NONE] : $ids).'}';
    }
}
